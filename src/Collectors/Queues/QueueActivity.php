<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two timestamps per watched queue, in cache only — the same mechanics as the
 * schedule collector, no table (ADR 004 T5, S6): the last time a job was put on
 * the queue, and the last time a worker started one.
 *
 * That is the one signal of a dead worker every driver can give, Redis and SQS
 * on Laravel 11 and 12 < 12.20 included, where the age of the oldest pending
 * job cannot be read at all (T16). It never replaces that age and never feeds
 * `oldest_pending_age_seconds`: it rides next to it as `stalled`.
 *
 * **The client's SQL transaction is never touched.** With the `database` cache
 * store — Laravel's default — a cache write *is* an SQL statement, on a
 * connection the client may be inside a transaction on. Writing there would
 * take a lock every concurrent dispatch then waits on, and on PostgreSQL a
 * failing cache write aborts the client's whole transaction, whatever we catch.
 * So a timestamp is only ever held in memory by the listener, and written:
 *
 * - in an HTTP request: once, when the application terminates — after the
 *   response is sent, outside any transaction;
 * - in a console process or a worker: at once when no transaction is open on
 *   any resolved connection, otherwise after the transaction commits (or at
 *   the next write opportunity, or when the process terminates).
 *
 * Writes are throttled twice: in the process (one per queue and per minute),
 * and across processes — FPM builds this object anew for every request — by
 * reading the stored value first and writing only if it is a minute old. A
 * stored timestamp therefore lags the real one by less than THROTTLE_SECONDS
 * and never leads it, which is the slack every comparison below allows.
 */
final class QueueActivity
{
    /** Write throttle, and the slack every comparison allows for it. */
    public const THROTTLE_SECONDS = 60;

    /** Same horizon as the schedule history: an idle queue keeps its last start for a quarter. */
    public const TTL = 7776000;

    public const QUEUED = 'queued';

    public const STARTED = 'started';

    /** @var array<string, int> Last timestamp this process recorded, by kind, connection and queue. */
    private array $recorded = [];

    /** @var array<string, int> Cache key to timestamp, waiting for a moment outside any transaction. */
    private array $pending = [];

    private bool $terminatingRegistered = false;

    private bool $commitHookArmed = false;

    private bool $warned = false;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    public function queued(string $connection, ?string $queue, int $at): void
    {
        $this->record(self::QUEUED, $connection, $queue, $at);
    }

    public function started(string $connection, ?string $queue, int $at): void
    {
        $this->record(self::STARTED, $connection, $queue, $at);
    }

    /**
     * The verdict for each queue, in the order given. Read only: collecting —
     * `--dry-run` included — never writes anything here.
     *
     * `stalled` is true when jobs were queued after the last start and no job
     * started for `stalled_after_seconds`, while the queue still holds work.
     * A single sighting is enough on the SDK side: the server opens an
     * incident only on two consecutive `critical` snapshots (ADR 001 §2), which
     * is what filters an idle queue that received a job a second before this
     * snapshot.
     *
     * `stalled` is false when the depth is readable and zero, whatever the
     * timestamps say: `queue:clear`, or a queue drained by a worker whose last
     * start the throttle has not stored yet, leaves nothing for a worker to do.
     *
     * `stalled` is null when the SDK cannot tell: array cache, unreachable
     * cache, or jobs queued while no start was ever observed — which is exactly
     * what a worker started before the package was installed looks like, so it
     * is never reported as dead (see `php artisan about`).
     *
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @param  array<int, int|null>  $pending  Depth by index of `$queues`, null when unread.
     * @return list<array{last_queued_at: string|null, last_started_at: string|null, stalled: bool|null}>
     */
    public function assess(array $queues, CarbonImmutable $now, array $pending = []): array
    {
        $unknown = array_map(
            fn (): array => ['last_queued_at' => null, 'last_started_at' => null, 'stalled' => null],
            $queues
        );

        if ($queues === []) {
            return [];
        }

        try {
            if ($this->usesArrayStore()) {
                return $unknown;
            }

            $observed = $this->observed($queues);
        } catch (Throwable $exception) {
            $this->warn('Constellation could not read its queue activity.', $exception);

            return $unknown;
        }

        $threshold = max(1, (int) $this->config->get('constellation.queues.stalled_after_seconds', 3600));
        $verdicts = [];

        foreach ($queues as $index => $queue) {
            ['queued' => $queued, 'started' => $started] = $observed[$index];

            $verdicts[] = [
                'last_queued_at' => $this->iso($queued),
                'last_started_at' => $this->iso($started),
                'stalled' => $this->verdict($queued, $started, $pending[$index] ?? null, $now->getTimestamp(), $threshold),
            ];
        }

        return $verdicts;
    }

    /**
     * Raw timestamps, for the collector and for `php artisan about`.
     *
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @return list<array{queued: int|null, started: int|null}>
     */
    public function observed(array $queues): array
    {
        $keys = [];

        foreach ($queues as $queue) {
            $keys[] = $this->key($queue['connection'], $queue['name'], self::QUEUED);
            $keys[] = $this->key($queue['connection'], $queue['name'], self::STARTED);
        }

        // One round trip for the whole inventory.
        $values = $this->store()->many($keys);
        $observed = [];

        foreach ($queues as $queue) {
            $observed[] = [
                'queued' => $this->timestamp($values[$this->key($queue['connection'], $queue['name'], self::QUEUED)] ?? null),
                'started' => $this->timestamp($values[$this->key($queue['connection'], $queue['name'], self::STARTED)] ?? null),
            ];
        }

        return $observed;
    }

    /**
     * Nothing survives between the worker process and the collecting one.
     */
    public function usesArrayStore(): bool
    {
        $store = $this->store();

        return $store instanceof CacheRepository && $store->getStore() instanceof ArrayStore;
    }

    /**
     * Writes what is waiting, unless a transaction is open on any connection —
     * then it keeps waiting. Never throws.
     */
    public function flush(): void
    {
        $this->commitHookArmed = false;

        if ($this->pending === []) {
            return;
        }

        try {
            if ($this->inTransaction()) {
                return;
            }

            $pending = $this->pending;
            $this->pending = [];

            $store = $this->store();
            $stored = $store->many(array_keys($pending));

            foreach ($pending as $key => $at) {
                $current = $stored[$key] ?? null;

                // Another process stored this within the minute (or later):
                // the cross-process half of the throttle.
                if (is_numeric($current) && $at - (int) $current < self::THROTTLE_SECONDS) {
                    continue;
                }

                $store->put($key, $at, self::TTL);
            }
        } catch (Throwable $exception) {
            $this->warn('Constellation could not record queue activity.', $exception);
        }
    }

    /**
     * Whether anything is still held in memory — for tests and diagnostics.
     */
    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * One identity for a queue, whichever side names it. Both events can carry
     * a null queue for "the connection's default"; an SQS job reports the full
     * queue URL while the inventory (T13) holds the configured name. For SQS the
     * identity is therefore the last segment of the URL `SqsQueue::getQueue()`
     * would build — suffix applied with `Str::finish()`, before any `.fifo` —
     * rather than an attempt to strip a suffix the name may already end with.
     */
    public function canonical(string $connection, ?string $queue): string
    {
        if (! is_string($queue) || $queue === '') {
            $default = $this->config->get('queue.connections.'.$connection.'.queue');
            $queue = is_string($default) && $default !== '' ? $default : 'default';
        }

        if ($this->driver($connection) !== 'sqs') {
            return $queue;
        }

        if (filter_var($queue, FILTER_VALIDATE_URL) !== false) {
            return basename((string) parse_url($queue, PHP_URL_PATH));
        }

        $suffix = $this->config->get('queue.connections.'.$connection.'.suffix');
        $suffix = is_string($suffix) ? $suffix : '';
        $fifo = str_ends_with($queue, '.fifo');
        $name = $fifo ? substr($queue, 0, -5) : $queue;

        if ($suffix !== '' && ! str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        return $fifo ? $name.'.fifo' : $name;
    }

    /**
     * @param  string  $queue  Any form `canonical()` accepts.
     */
    public function key(string $connection, string $queue, string $kind): string
    {
        $queue = $this->canonical($connection, $queue);
        $prefix = (string) $this->config->get('constellation.cache.prefix', 'constellation');
        $fingerprint = substr(hash('sha256', (string) $this->config->get('constellation.token')), 0, 12);

        // Hashed: an SQS URL or a queue name with spaces is not a valid key on
        // every store (memcached refuses both).
        return $prefix.':queues:'.$fingerprint.':'.substr(sha1($connection."\0".$queue), 0, 16).':'.$kind;
    }

    private function verdict(?int $queued, ?int $started, ?int $pending, int $now, int $threshold): ?bool
    {
        // Readable and empty: nothing waits, whatever the timestamps say.
        if ($pending === 0) {
            return false;
        }

        // The cache answered and no dispatch was ever seen: nothing waits.
        if ($queued === null) {
            return false;
        }

        // A dead worker and a worker that has not been restarted since the
        // package was installed are indistinguishable here. Saying "dead"
        // would be a false alarm on every fresh install.
        if ($started === null) {
            return null;
        }

        // A dispatch after the last start, beyond what the throttle can explain,
        // and no start since for the whole threshold.
        return $queued > $started + self::THROTTLE_SECONDS && $now - $started >= $threshold;
    }

    /**
     * Called from inside the client's dispatch and from inside their worker: it
     * must never throw, and never touches the database while a transaction is
     * open.
     */
    private function record(string $kind, string $connection, ?string $queue, int $at): void
    {
        try {
            $driver = $this->driver($connection);

            // sync and null run nothing through a worker; an unknown connection
            // is nothing the inventory will ever list (T19).
            if ($driver === null || in_array($driver, QueueInventory::IGNORED_DRIVERS, true)) {
                return;
            }

            $memo = $kind."\0".$connection."\0".$this->canonical($connection, $queue);

            if (isset($this->recorded[$memo]) && $at - $this->recorded[$memo] < self::THROTTLE_SECONDS) {
                return;
            }

            $this->recorded[$memo] = $at;

            $key = $this->key($connection, (string) $queue, $kind);
            $this->pending[$key] = max($this->pending[$key] ?? 0, $at);

            if ($this->app->runningInConsole() && ! $this->inTransaction()) {
                $this->flush();

                return;
            }

            $this->deferFlush();
        } catch (Throwable $exception) {
            $this->warn('Constellation could not record queue activity.', $exception);
        }
    }

    /**
     * HTTP: after the response, when the application terminates. Console and
     * worker: right after the outermost transaction commits, with termination
     * as the backstop (a rolled back transaction never runs its callbacks).
     */
    private function deferFlush(): void
    {
        if (! $this->terminatingRegistered) {
            $this->terminatingRegistered = true;
            $this->app->terminating(function (): void {
                $this->flush();
            });
        }

        if ($this->commitHookArmed || ! $this->app->runningInConsole() || ! $this->app->bound('db.transactions')) {
            return;
        }

        $this->commitHookArmed = true;
        $this->app->make('db.transactions')->addCallback(function (): void {
            $this->flush();
        });
    }

    /**
     * Any open transaction on any connection already resolved. A connection
     * never resolved cannot hold one, and resolving the manager just to ask
     * would cost the client a connection.
     */
    private function inTransaction(): bool
    {
        if (! $this->app->resolved('db')) {
            return false;
        }

        foreach ($this->app->make('db')->getConnections() as $connection) {
            if ($connection->transactionLevel() > 0) {
                return true;
            }
        }

        return false;
    }

    private function driver(string $connection): ?string
    {
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    private function timestamp(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function iso(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : CarbonImmutable::createFromTimestamp($timestamp, 'UTC')->toIso8601ZuluString();
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->get('constellation.cache.store'));
    }

    /**
     * Once per process: a Redis outage must not write a warning for every job
     * the client dispatches.
     */
    private function warn(string $message, Throwable $exception): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        Log::warning($message, ['exception' => $exception->getMessage()]);
    }
}
