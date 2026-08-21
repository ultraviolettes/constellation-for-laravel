<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owns the only piece of state the SDK keeps between two snapshots: the window
 * cursor. It is a bound, never a counter — the server holds the cumulative
 * totals (ADR 001 D18).
 */
final class WindowResolver
{
    public const REASON_TRUNCATED = 'window_truncated';

    public const REASON_ARRAY_DRIVER = 'cache_driver_array';

    public const REASON_CACHE_UNAVAILABLE = 'cache_unavailable';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    public function resolve(CarbonImmutable $to): Window
    {
        $fallbackMinutes = (int) $this->config->get('constellation.window.fallback_minutes', 15);
        $maxHours = (int) $this->config->get('constellation.window.max_hours', 24);

        try {
            $repository = $this->store();
            $cursor = $this->cursor($repository);
            $arrayStore = $repository instanceof CacheRepository && $repository->getStore() instanceof ArrayStore;
        } catch (Throwable $exception) {
            // A cache the client cannot reach must degrade the snapshot, never
            // crash their scheduler. Logged as a warning rather than reported,
            // so a Redis outage does not flood their exception tracker.
            Log::warning('Constellation could not read its window cursor.', [
                'exception' => $exception->getMessage(),
            ]);

            return new Window(
                $to->subMinutes($fallbackMinutes),
                $to,
                Window::SOURCE_INTERVAL_FALLBACK,
                [self::REASON_CACHE_UNAVAILABLE],
            );
        }

        $source = $cursor === null ? Window::SOURCE_INTERVAL_FALLBACK : Window::SOURCE_CURSOR;
        $from = $cursor ?? $to->subMinutes($fallbackMinutes);
        $degradations = [];

        $floor = $to->subHours($maxHours);

        if ($from->lessThan($floor)) {
            $from = $floor;
            $source = Window::SOURCE_CURSOR_TRUNCATED;
            $degradations[] = self::REASON_TRUNCATED;
        }

        if ($arrayStore) {
            $from = $to->subMinutes($fallbackMinutes);
            $source = Window::SOURCE_INTERVAL_FALLBACK;
            $degradations[] = self::REASON_ARRAY_DRIVER;
        }

        return new Window($from, $to, $source, $degradations);
    }

    /**
     * Called from the send job only, and only on a 202 (S18). A snapshot that
     * was built then lost must leave its window open for the next one.
     */
    public function advance(CarbonImmutable $windowTo, CarbonImmutable $sentAt): void
    {
        try {
            $this->store()->put(
                $this->key(),
                [
                    'window_to' => $windowTo->toIso8601ZuluString(),
                    'sent_at' => $sentAt->toIso8601ZuluString(),
                ],
                (int) $this->config->get('constellation.cache.ttl_days', 7) * 86400,
            );
        } catch (Throwable $exception) {
            // The snapshot was accepted; only the next window overlaps (R6).
            Log::warning('Constellation could not advance its window cursor.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function key(): string
    {
        $prefix = (string) $this->config->get('constellation.cache.prefix', 'constellation');
        $fingerprint = substr(hash('sha256', (string) $this->config->get('constellation.token')), 0, 12);

        return $prefix.':cursor:'.$fingerprint;
    }

    private function cursor(Repository $repository): ?CarbonImmutable
    {
        $stored = $repository->get($this->key());

        if (! is_array($stored) || ! isset($stored['window_to']) || ! is_string($stored['window_to'])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($stored['window_to'])->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->get('constellation.cache.store'));
    }
}
