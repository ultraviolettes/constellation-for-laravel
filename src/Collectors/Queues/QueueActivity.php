<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
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
 * Writes are throttled per process: one `put()` per queue and per minute at
 * most, whatever the job volume. The throttle makes a stored timestamp lag the
 * real one by less than THROTTLE_SECONDS, never lead it — which is why every
 * comparison below allows exactly that much slack.
 */
final class QueueActivity
{
    /** Per-process write throttle, and the slack every comparison allows for it. */
    public const THROTTLE_SECONDS = 60;

    /** Same horizon as the schedule history: an idle queue keeps its last start for a quarter. */
    public const TTL = 7776000;

    /** The suspicion only has to outlive the next snapshot. */
    public const SUSPECT_TTL = 86400;

    public const QUEUED = 'queued';

    public const STARTED = 'started';

    public const SUSPECT = 'suspect';

    /** @var array<string, int> Last write of this process, by kind, connection and queue. */
    private array $written = [];

    private bool $warned = false;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
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
     * The verdict for each queue, in the order given.
     *
     * `stalled` is true only once a stall has been *confirmed*: jobs were queued
     * after the last start, no job started for `stalled_after_seconds`, and an
     * earlier collection already saw the very same last start at least a minute
     * before. The confirmation is what tells a dead worker from a queue that was
     * idle for hours and received a job a second before this snapshot — both
     * look identical through two timestamps.
     *
     * `stalled` is null when the SDK cannot tell: array cache, unreachable
     * cache, or jobs queued while no start was ever observed — which is exactly
     * what a worker started before the package was installed looks like, so it
     * is never reported as dead (see `php artisan about`).
     *
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @return list<array{last_queued_at: string|null, last_started_at: string|null, stalled: bool|null}>
     */
    public function assess(array $queues, CarbonImmutable $now): array
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

            $observed = $this->observed($queues, withSuspects: true);
        } catch (Throwable $exception) {
            $this->warn('Constellation could not read its queue activity.', $exception);

            return $unknown;
        }

        $threshold = max(1, (int) $this->config->get('constellation.queues.stalled_after_seconds', 3600));
        $verdicts = [];

        foreach ($queues as $index => $queue) {
            ['queued' => $queued, 'started' => $started, 'suspect' => $suspect] = $observed[$index];

            $verdicts[] = [
                'last_queued_at' => $this->iso($queued),
                'last_started_at' => $this->iso($started),
                'stalled' => $this->verdict($queue, $queued, $started, $suspect, $now, $threshold),
            ];
        }

        return $verdicts;
    }

    /**
     * Raw timestamps, for the collector and for `php artisan about`.
     *
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @return list<array{queued: int|null, started: int|null, suspect: array{started_at: int|null, seen_at: int}|null}>
     */
    public function observed(array $queues, bool $withSuspects = false): array
    {
        $keys = [];

        foreach ($queues as $queue) {
            $keys[] = $this->key($queue['connection'], $queue['name'], self::QUEUED);
            $keys[] = $this->key($queue['connection'], $queue['name'], self::STARTED);

            if ($withSuspects) {
                $keys[] = $this->key($queue['connection'], $queue['name'], self::SUSPECT);
            }
        }

        // One round trip for the whole inventory.
        $values = $this->store()->many($keys);
        $observed = [];

        foreach ($queues as $queue) {
            $suspect = $withSuspects
                ? $values[$this->key($queue['connection'], $queue['name'], self::SUSPECT)] ?? null
                : null;

            $observed[] = [
                'queued' => $this->timestamp($values[$this->key($queue['connection'], $queue['name'], self::QUEUED)] ?? null),
                'started' => $this->timestamp($values[$this->key($queue['connection'], $queue['name'], self::STARTED)] ?? null),
                'suspect' => is_array($suspect) && isset($suspect['seen_at']) && is_numeric($suspect['seen_at'])
                    ? [
                        'started_at' => is_numeric($suspect['started_at'] ?? null) ? (int) $suspect['started_at'] : null,
                        'seen_at' => (int) $suspect['seen_at'],
                    ]
                    : null,
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

    /**
     * @param  array{connection: string, name: string, driver: string}  $queue
     * @param  array{started_at: int|null, seen_at: int}|null  $suspect
     */
    private function verdict(array $queue, ?int $queued, ?int $started, ?array $suspect, CarbonImmutable $now, int $threshold): ?bool
    {
        $now = $now->getTimestamp();
        $suspectKey = $this->key($queue['connection'], $queue['name'], self::SUSPECT);

        if ($queued === null) {
            $this->forgetSuspect($suspect, $suspectKey);

            // The cache answered and no dispatch was ever seen: nothing waits.
            return false;
        }

        if ($started === null) {
            // A dead worker and a worker that has not been restarted since the
            // package was installed are indistinguishable here. Saying "dead"
            // would be a false alarm on every fresh install.
            $this->forgetSuspect($suspect, $suspectKey);

            return null;
        }

        // A dispatch after the last start, beyond what the throttle can explain,
        // and no start since for the whole threshold.
        $candidate = $queued > $started + self::THROTTLE_SECONDS && $now - $started >= $threshold;

        if (! $candidate) {
            $this->forgetSuspect($suspect, $suspectKey);

            return false;
        }

        if ($suspect !== null && $suspect['started_at'] === $started) {
            return $now - $suspect['seen_at'] >= self::THROTTLE_SECONDS;
        }

        try {
            $this->store()->put($suspectKey, ['started_at' => $started, 'seen_at' => $now], self::SUSPECT_TTL);
        } catch (Throwable $exception) {
            $this->warn('Constellation could not record a queue stall suspicion.', $exception);
        }

        return false;
    }

    /**
     * @param  array{started_at: int|null, seen_at: int}|null  $suspect
     */
    private function forgetSuspect(?array $suspect, string $key): void
    {
        if ($suspect === null) {
            return;
        }

        try {
            $this->store()->forget($key);
        } catch (Throwable $exception) {
            $this->warn('Constellation could not clear a queue stall suspicion.', $exception);
        }
    }

    /**
     * Called from inside the client's dispatch and from inside their worker: it
     * must never throw, and costs at most one cache write per queue per minute.
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

            $name = $this->canonical($connection, $queue);
            $memo = $kind."\0".$connection."\0".$name;

            if (isset($this->written[$memo]) && $at - $this->written[$memo] < self::THROTTLE_SECONDS) {
                return;
            }

            // Set before the write: a cache that just failed is not hammered
            // again for every job of the next minute.
            $this->written[$memo] = $at;

            $this->store()->put($this->key($connection, $name, $kind), $at, self::TTL);
        } catch (Throwable $exception) {
            $this->warn('Constellation could not record queue activity.', $exception);
        }
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
