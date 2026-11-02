<?php

namespace Ultraviolettes\Constellation\Console;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Collectors\Queues\QueueInventory;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Constellation;
use Ultraviolettes\Constellation\Snapshot\LastDelivery;

/**
 * The "Constellation" section of `php artisan about` (veille Laralyze, idea 3):
 * the first thing support asks for, readable by the client in one command.
 *
 * Every line is computed from configuration and cache only — no query, no
 * network — and every line survives its own failure: `about` is what someone
 * runs when something is already wrong. The token value is never printed, only
 * whether there is one.
 */
final class AboutConstellation
{
    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly Config $config,
        private readonly LastDelivery $delivery,
        private readonly QueueInventory $inventory,
        private readonly QueueActivity $activity,
    ) {}

    /**
     * @return array<string, Closure(bool): mixed>
     */
    public function __invoke(): array
    {
        $enabled = (bool) $this->config->get('constellation.enabled');
        $token = ! blank($this->config->get('constellation.token'));
        [$lastSend, $unavailable] = $this->delivery();

        return [
            'Version' => $this->show(Constellation::VERSION),
            'Enabled' => $this->show($enabled, fn (bool $on): string => $on ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>'),
            'Token' => $this->show($token, fn (bool $present): string => $present ? '<fg=green>configured</>' : '<fg=red;options=bold>MISSING</> (CONSTELLATION_TOKEN)'),
            'Endpoint' => $this->show((string) $this->config->get('constellation.endpoint')),
            'Cache store' => $this->cacheStore(),
            'Last successful send' => $lastSend,
            'Unavailable collectors' => $unavailable,
            'Queue workers' => $this->workers($enabled && $token),
        ];
    }

    /**
     * @return Closure(bool): mixed
     */
    private function cacheStore(): Closure
    {
        $name = $this->config->get('constellation.cache.store') ?? $this->config->get('cache.default');
        $name = is_string($name) ? $name : '';
        $driver = $this->config->get('cache.stores.'.$name.'.driver');
        $driver = is_string($driver) ? $driver : self::UNKNOWN;

        return $this->show(
            ['store' => $name, 'driver' => $driver],
            fn (array $store): string => $store['driver'] === 'array'
                // Every history the SDK keeps (window cursor, schedule, queue
                // activity) dies with the process that wrote it.
                ? '<fg=yellow;options=bold>'.$store['store'].' (array)</> nothing survives between processes'
                : $store['store'].' ('.$store['driver'].')',
        );
    }

    /**
     * One cache read for both lines it feeds.
     *
     * @return array{0: Closure(bool): mixed, 1: Closure(bool): mixed}
     */
    private function delivery(): array
    {
        try {
            $delivery = $this->delivery->read();
            $sentAt = $delivery === null ? null : CarbonImmutable::parse($delivery['sent_at'])->utc();
        } catch (Throwable) {
            $unknown = $this->show(null, fn (): string => self::UNKNOWN.' (unreadable)');

            return [$unknown, $unknown];
        }

        $lastSend = $this->show(
            $sentAt?->toIso8601ZuluString(),
            fn (): string => $sentAt === null
                ? '<fg=yellow>never</> (php artisan monitor:send --sync)'
                : $sentAt->format('Y-m-d H:i:s').' UTC ('.$sentAt->diffForHumans().')',
        );

        // Unknown and none are two different answers (rule 2 of §2).
        $unavailable = $this->show($delivery['unavailable'] ?? null, function (?array $sections): string {
            if ($sections === null) {
                return self::UNKNOWN;
            }

            if ($sections === []) {
                return 'none';
            }

            return implode(', ', array_map(
                fn (string $key, string $reason): string => $reason === '' ? $key : $key.' ('.$reason.')',
                array_keys($sections),
                $sections,
            ));
        });

        return [$lastSend, $unavailable];
    }

    /**
     * The workers-not-restarted check of the veille (idea 4): the queue
     * listeners only exist in a worker booted after the package was installed.
     * Jobs seen queued while no worker was ever seen starting one is exactly
     * what a worker that predates the install looks like.
     *
     * @return Closure(bool): mixed
     */
    private function workers(bool $recording): Closure
    {
        if (! $recording || ! in_array(QueuesCollector::class, (array) $this->config->get('constellation.collectors', []), true)) {
            return $this->show('not_watched', fn (): string => 'not watched');
        }

        try {
            if ($this->activity->usesArrayStore()) {
                return $this->show(self::UNKNOWN, fn (): string => self::UNKNOWN.' (array cache)');
            }

            $queues = $this->inventory->configured();
            $observed = $this->activity->observed($queues);
        } catch (Throwable) {
            return $this->show(self::UNKNOWN, fn (): string => self::UNKNOWN.' (cache unreachable)');
        }

        $unstarted = [];
        $started = false;

        foreach ($queues as $index => $queue) {
            $started = $started || $observed[$index]['started'] !== null;

            if ($observed[$index]['queued'] !== null && $observed[$index]['started'] === null) {
                $unstarted[] = $queue['connection'].':'.$queue['name'];
            }
        }

        if ($unstarted !== []) {
            return $this->show(
                'restart_needed',
                fn (): string => '<fg=yellow;options=bold>jobs queued, no start seen</> on '.implode(', ', $unstarted)
                    .' — restart your workers (php artisan queue:restart, or horizon:terminate)',
            );
        }

        return $started
            ? $this->show('observed', fn (): string => 'job starts observed')
            : $this->show('idle', fn (): string => 'no queue activity seen yet');
    }

    /**
     * The value as is for `--json`, formatted for the console otherwise.
     *
     * @return Closure(bool): mixed
     */
    private function show(mixed $value, ?Closure $console = null): Closure
    {
        return function (bool $json = false) use ($value, $console): mixed {
            if ($json || $console === null) {
                return $value;
            }

            return $console($value);
        };
    }
}
