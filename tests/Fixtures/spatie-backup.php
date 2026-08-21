<?php

/**
 * Minimal doubles of the `spatie/laravel-backup` API, under the names the
 * collector probes and calls (ADR 004 T20, T21, R5). The package itself is not a
 * dependency of the SDK — not even a dev one: what has to be frozen is the shape
 * of the call, and a double states it without pinning a version.
 *
 * Loaded on demand rather than autoloaded: a declared class cannot be undeclared,
 * and the "package absent" path needs a process that has never heard of them.
 */

namespace Spatie\Backup\BackupDestination {
    use DateTimeInterface;
    use Illuminate\Support\Collection;
    use RuntimeException;

    class Backup
    {
        public function __construct(private readonly DateTimeInterface $date) {}

        public function date(): DateTimeInterface
        {
            return $this->date;
        }
    }

    class BackupDestination
    {
        /**
         * @param  list<string>  $explodesOn  Accessors that throw, as an unreachable disk makes them.
         */
        public function __construct(
            private readonly string $disk = 's3',
            private readonly string $backupName = 'acme',
            private readonly ?Backup $newest = null,
            private readonly float $usedStorage = 0.0,
            private readonly int $count = 0,
            private readonly array $explodesOn = [],
        ) {}

        public function diskName(): string
        {
            return $this->guard('diskName', fn (): string => $this->disk);
        }

        public function backupName(): string
        {
            return $this->guard('backupName', fn (): string => $this->backupName);
        }

        public function newestBackup(): ?Backup
        {
            return $this->guard('newestBackup', fn (): ?Backup => $this->newest);
        }

        public function usedStorage(): float
        {
            return $this->guard('usedStorage', fn (): float => $this->usedStorage);
        }

        public function backups(): Collection
        {
            return $this->guard('backups', fn (): Collection => new Collection(array_fill(0, $this->count, $this->newest)));
        }

        private function guard(string $accessor, callable $read): mixed
        {
            if (in_array($accessor, $this->explodesOn, true)) {
                throw new RuntimeException('the backup disk is unreachable');
            }

            return $read();
        }
    }
}

namespace Spatie\Backup\Tasks\Monitor {
    use Illuminate\Support\Collection;
    use RuntimeException;
    use Spatie\Backup\BackupDestination\BackupDestination;

    class BackupDestinationStatus
    {
        /**
         * @param  list<array{check: string, message: string}>  $failures
         */
        public function __construct(
            private readonly BackupDestination $destination,
            private readonly bool $healthy = true,
            private readonly array $failures = [],
            private readonly bool $healthExplodes = false,
        ) {}

        public function backupDestination(): BackupDestination
        {
            return $this->destination;
        }

        public function isHealthy(): bool
        {
            if ($this->healthExplodes) {
                throw new RuntimeException('the health check could not run');
            }

            return $this->healthy;
        }

        public function failureMessages(): Collection
        {
            return new Collection($this->failures);
        }
    }

    class BackupDestinationStatusFactory
    {
        /** @var callable(array<string, mixed>): iterable<mixed>|null */
        public static $resolver = null;

        /** @var list<array<string, mixed>> Every monitor config the collector passed. */
        public static array $received = [];

        public static function reset(): void
        {
            self::$resolver = null;
            self::$received = [];
        }

        /**
         * @param  array<string, mixed>  $monitorConfig
         * @return iterable<mixed>
         */
        public static function createForSingleMonitor(array $monitorConfig): iterable
        {
            self::$received[] = $monitorConfig;

            $resolver = self::$resolver;

            if ($resolver === null) {
                throw new RuntimeException('no resolver was set on the factory double');
            }

            return $resolver($monitorConfig);
        }
    }
}
