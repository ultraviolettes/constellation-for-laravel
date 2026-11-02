<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the last *accepted* snapshot said about itself, for `php artisan about`
 * and for support: when it left, and which collectors could not look.
 *
 * Written by the send job on a 202 only, next to the window cursor (S18) and
 * never instead of it: the cursor is a bound the next window depends on, this
 * is a diagnostic nothing depends on. Losing it costs a line of `about`.
 */
final class LastDelivery
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
        private readonly WindowResolver $windows,
    ) {}

    public function record(string $body, string $windowTo, CarbonImmutable $sentAt): void
    {
        try {
            $this->store()->put($this->key(), [
                'sent_at' => $sentAt->toIso8601ZuluString(),
                'window_to' => $windowTo,
                'unavailable' => $this->unavailable($body),
            ], (int) $this->config->get('constellation.cache.ttl_days', 7) * 86400);
        } catch (Throwable $exception) {
            Log::warning('Constellation could not record its last delivery.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Null when nothing was ever accepted under this token. A cursor written by
     * a version that did not record deliveries still gives the date.
     *
     * @return array{sent_at: string, unavailable: array<string, string>|null}|null
     */
    public function read(): ?array
    {
        $stored = $this->store()->get($this->key());

        if (is_array($stored) && is_string($stored['sent_at'] ?? null)) {
            $unavailable = is_array($stored['unavailable'] ?? null) ? $stored['unavailable'] : [];

            return [
                'sent_at' => $stored['sent_at'],
                'unavailable' => array_map(strval(...), array_filter($unavailable, is_string(...))),
            ];
        }

        $cursor = $this->store()->get($this->windows->key());

        if (is_array($cursor) && is_string($cursor['sent_at'] ?? null)) {
            return ['sent_at' => $cursor['sent_at'], 'unavailable' => null];
        }

        return null;
    }

    public function key(): string
    {
        $prefix = (string) $this->config->get('constellation.cache.prefix', 'constellation');
        $fingerprint = substr(hash('sha256', (string) $this->config->get('constellation.token')), 0, 12);

        return $prefix.':delivery:'.$fingerprint;
    }

    /**
     * Section key to reason, read back from the very body the server accepted.
     *
     * @return array<string, string>
     */
    private function unavailable(string $body): array
    {
        $payload = json_decode($body, true);
        $sections = is_array($payload) && is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $unavailable = [];

        foreach ($sections as $key => $section) {
            if (is_array($section) && ($section['status'] ?? null) === Status::Unavailable->value) {
                $unavailable[(string) $key] = is_string($section['reason'] ?? null) ? $section['reason'] : '';
            }
        }

        return $unavailable;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->get('constellation.cache.store'));
    }
}
