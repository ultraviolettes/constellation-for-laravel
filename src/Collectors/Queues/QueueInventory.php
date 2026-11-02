<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The list of queues worth watching, by union of four free sources (ADR 004 T13).
 * Horizon contributes through its *configuration* only: no dependency, no call.
 */
final class QueueInventory
{
    public const IGNORED_DRIVERS = ['sync', 'null'];

    /** Probed, never required, never called: only its configuration is read. */
    public const HORIZON = 'Laravel\\Horizon\\Horizon';

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseJobsReader $jobs,
    ) {}

    /**
     * @return list<array{connection: string, name: string, driver: string}>
     */
    public function all(CarbonImmutable $now): array
    {
        $found = [];

        foreach (array_keys($this->connections()) as $connection) {
            $this->add($found, $connection, $this->defaultQueue($connection));
        }

        foreach ($this->monitored() as [$connection, $queue]) {
            $this->add($found, $connection, $queue);
        }

        // Same GROUP BY that reads the depth: a queue only ever named at dispatch
        // time exists nowhere in configuration (T13).
        foreach (array_keys($this->connections()) as $connection) {
            if ($this->driver($connection) !== 'database') {
                continue;
            }

            foreach (array_keys($this->jobs->rows($connection, $now)) as $queue) {
                $this->add($found, $connection, (string) $queue);
            }
        }

        foreach ($this->horizon() as [$connection, $queue]) {
            $this->add($found, $connection, $queue);
        }

        return array_values($found);
    }

    /**
     * The same inventory from configuration alone — no query, no I/O — for
     * diagnostics that must stay instant even when the database is down.
     *
     * @return list<array{connection: string, name: string, driver: string}>
     */
    public function configured(): array
    {
        $found = [];

        foreach (array_keys($this->connections()) as $connection) {
            $this->add($found, $connection, $this->defaultQueue($connection));
        }

        foreach ([...$this->monitored(), ...$this->horizon()] as [$connection, $queue]) {
            $this->add($found, $connection, $queue);
        }

        return array_values($found);
    }

    /**
     * @param  array<string, array{connection: string, name: string, driver: string}>  $found
     */
    private function add(array &$found, string $connection, string $queue): void
    {
        $driver = $this->driver($connection);

        // An unknown connection cannot be resolved, and sync/null have nothing
        // to watch: neither is a degradation, both are simply out of scope (T19).
        if ($driver === null || in_array($driver, self::IGNORED_DRIVERS, true)) {
            return;
        }

        $key = $connection."\0".$queue;

        $found[$key] ??= ['connection' => $connection, 'name' => $queue, 'driver' => $driver];
    }

    /**
     * The connections the application actually claims: its default, and whatever
     * the client asked for by hand. A stock `config/queue.php` declares every
     * driver Laravel ships with, and reading an unused one always fails — that is
     * a configuration leftover, not a failed observation.
     *
     * @return list<string>
     */
    public function claimed(): array
    {
        $claimed = [(string) $this->config->get('queue.default')];

        foreach ($this->monitored() as [$connection]) {
            $claimed[] = $connection;
        }

        return array_values(array_unique($claimed));
    }

    /**
     * @return array<string, mixed>
     */
    private function connections(): array
    {
        $connections = $this->config->get('queue.connections');

        return is_array($connections) ? $connections : [];
    }

    private function driver(string $connection): ?string
    {
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    private function defaultQueue(string $connection): string
    {
        $queue = $this->config->get('queue.connections.'.$connection.'.queue');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    /**
     * `connection:queue` as `queue:monitor` parses it: a bare token names a queue
     * on the default connection.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function monitored(): array
    {
        $entries = $this->config->get('constellation.queues.monitor', []);
        $default = (string) $this->config->get('queue.default');
        $parsed = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            foreach (explode(',', $entry) as $token) {
                $token = trim($token);

                if ($token === '') {
                    continue;
                }

                [$connection, $queue] = array_pad(explode(':', $token, 2), 2, null);

                $parsed[] = $queue === null || $queue === ''
                    ? [$default, (string) $connection]
                    : [(string) $connection, $queue];
            }
        }

        return $parsed;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function horizon(): array
    {
        if (! class_exists(self::HORIZON)) {
            return [];
        }

        $environments = $this->config->get('horizon.environments');
        $parsed = [];

        foreach (is_array($environments) ? $environments : [] as $supervisors) {
            foreach (is_array($supervisors) ? $supervisors : [] as $supervisor) {
                if (! is_array($supervisor)) {
                    continue;
                }

                $connection = $supervisor['connection'] ?? null;

                if (! is_string($connection) || $connection === '') {
                    continue;
                }

                foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                    if (is_string($queue) && $queue !== '') {
                        $parsed[] = [$connection, $queue];
                    }
                }
            }
        }

        return $parsed;
    }
}
