<?php

namespace Ultraviolettes\Constellation\Collectors\Schedule;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only state the schedule collector keeps: execution history, one cache key
 * per task (ADR 004 T5). The inventory itself is never persisted — it is read
 * live from the scheduler at collection time (T3), so a task removed from the
 * schedule disappears from the section immediately and its key expires alone.
 */
final class TaskHistory
{
    public const WEEK = 604800;

    public const QUARTER = 7776000;

    /**
     * @var array{ran_at: int|null, duration_ms: int|null, exit_code: int|null, failed_at: int|null, exception_class: string|null, skipped_at: int|null, overlapped_at: int|null}
     */
    public const EMPTY = [
        'ran_at' => null,
        'duration_ms' => null,
        'exit_code' => null,
        'failed_at' => null,
        'exception_class' => null,
        'skipped_at' => null,
        'overlapped_at' => null,
    ];

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    /**
     * Merge-write from a scheduler listener. Never lets a cache outage break the
     * client's scheduler: a lost write costs one degraded field, a thrown
     * exception would cost them the task run.
     *
     * @param  array<string, int|string|null>  $changes
     */
    public function record(Event $task, array $changes): void
    {
        try {
            $key = $this->taskKey(TaskIdentity::of($task));
            $store = $this->store();
            $stored = $store->get($key);

            $store->put(
                $key,
                array_merge(self::EMPTY, is_array($stored) ? $stored : [], $changes),
                $this->ttl($task)
            );
        } catch (Throwable $exception) {
            $this->warn('Constellation could not record a scheduled task run.', $exception);
        }
    }

    /**
     * @return array{ran_at: int|null, duration_ms: int|null, exit_code: int|null, failed_at: int|null, exception_class: string|null, skipped_at: int|null, overlapped_at: int|null}
     */
    public function read(string $id): array
    {
        $stored = $this->store()->get($this->taskKey($id));

        if (! is_array($stored)) {
            return self::EMPTY;
        }

        $state = self::EMPTY;

        foreach (array_keys(self::EMPTY) as $field) {
            $value = $stored[$field] ?? null;

            if ($field === 'exception_class') {
                $state[$field] = is_string($value) ? $value : null;

                continue;
            }

            $state[$field] = is_numeric($value) ? (int) $value : null;
        }

        return $state;
    }

    /**
     * The base `missed` counts from for a task that has never run. Written once,
     * with `add()`, so that the very first snapshot reports no history rather
     * than a month of fabricated misses (T9, T11).
     *
     * @return array{at: CarbonImmutable, fresh: bool}
     */
    public function observedSince(CarbonImmutable $now, bool $persist = true): array
    {
        $key = $this->sinceKey();
        // A dry run reads the base and never lays it: an absent base reads as
        // fresh, exactly what the real run will then write.
        $fresh = $persist && $this->store()->add($key, $now->toIso8601ZuluString(), self::QUARTER);
        $stored = $this->store()->get($key);

        if ($fresh || ! is_string($stored)) {
            return ['at' => $now, 'fresh' => true];
        }

        try {
            return ['at' => CarbonImmutable::parse($stored)->utc(), 'fresh' => false];
        } catch (Throwable) {
            return ['at' => $now, 'fresh' => true];
        }
    }

    /**
     * Nothing survives between the `schedule:run` process and the collecting one.
     */
    public function usesArrayStore(): bool
    {
        $store = $this->store();

        return $store instanceof CacheRepository && $store->getStore() instanceof ArrayStore;
    }

    public function taskKey(string $id): string
    {
        return $this->prefix().':schedule:'.$this->fingerprint().':'.$id;
    }

    public function sinceKey(): string
    {
        return $this->prefix().':schedule:'.$this->fingerprint().':since';
    }

    /**
     * `clamp(3 x period, 7 days, 90 days)` (T10). A fixed week would erase the
     * history of a monthly task between two runs, and report it as never run.
     */
    private function ttl(Event $task): int
    {
        try {
            $period = $task->nextRunDate('now', 1)->getTimestamp() - $task->nextRunDate('now', 0)->getTimestamp();
        } catch (Throwable) {
            return self::WEEK;
        }

        return max(self::WEEK, min(self::QUARTER, 3 * max(0, $period)));
    }

    private function prefix(): string
    {
        return (string) $this->config->get('constellation.cache.prefix', 'constellation');
    }

    private function fingerprint(): string
    {
        return substr(hash('sha256', (string) $this->config->get('constellation.token')), 0, 12);
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->get('constellation.cache.store'));
    }

    private function warn(string $message, Throwable $exception): void
    {
        Log::warning($message, ['exception' => $exception->getMessage()]);
    }
}
