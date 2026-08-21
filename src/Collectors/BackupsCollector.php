<?php

namespace Ultraviolettes\Constellation\Collectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * Reads `spatie/laravel-backup` through its own verdict.
 *
 * The health checks are never reimplemented (ADR 004 T23): `isHealthy()` is what
 * the client already sees in `backup:monitor`, and a second opinion would
 * eventually disagree with the first.
 */
final class BackupsCollector implements Collector
{
    public const REASON_NOT_INSTALLED = 'collector_not_installed';

    public const REASON_HEALTH_UNAVAILABLE = 'backup_health_unavailable';

    /** Namespace and name unchanged from v6 to 10.3.2. */
    public const DESTINATION = 'Spatie\\Backup\\BackupDestination\\BackupDestination';

    /**
     * `createForMonitorConfig()` is deliberately avoided: its signature changed
     * from `array` to `MonitoredBackupsConfig` between v8 and v9 (T21).
     */
    public const FACTORY = 'Spatie\\Backup\\Tasks\\Monitor\\BackupDestinationStatusFactory';

    public function __construct(private readonly Config $config) {}

    public function key(): string
    {
        return 'backups';
    }

    public function collect(Window $window): Section
    {
        if (! class_exists(self::DESTINATION)) {
            return Section::unavailable($this->key(), self::REASON_NOT_INSTALLED);
        }

        $now = $window->to;
        $monitors = $this->config->get('backup.monitor_backups', []);
        $destinations = [];
        $degraded = false;

        foreach (is_array($monitors) ? $monitors : [] as $monitor) {
            if (! is_array($monitor)) {
                continue;
            }

            try {
                $statuses = $this->statuses($monitor);
            } catch (Throwable) {
                $degraded = true;

                continue;
            }

            foreach ($statuses as $status) {
                $destination = $this->describe($status, $now);
                $destinations[] = $destination;
                $degraded = $degraded || $destination['healthy'] === null;
            }
        }

        $section = Section::make($this->key(), $this->status($destinations), [
            'destinations' => $destinations,
        ], $now);

        return $degraded ? $section->degraded(self::REASON_HEALTH_UNAVAILABLE) : $section;
    }

    /**
     * Both spellings of the health check key travel together: v8 reads
     * `health_checks`, v9 and v10 read `healthChecks`, and the published config
     * file writes `health_checks`. Passing the raw config to v10 would silently
     * run zero checks — and report a destination healthy that is not (T21).
     *
     * @param  array<string, mixed>  $monitor
     * @return iterable<mixed>
     */
    private function statuses(array $monitor): iterable
    {
        $checks = $monitor['health_checks'] ?? $monitor['healthChecks'] ?? [];

        $statuses = call_user_func(
            [self::FACTORY, 'createForSingleMonitor'],
            $monitor + ['health_checks' => $checks, 'healthChecks' => $checks]
        );

        return is_iterable($statuses) ? $statuses : [];
    }

    /**
     * @return array{disk: string|null, backup_name: string|null, healthy: bool|null, last_successful_at: string|null, newest_age_seconds: int|null, count: int|null, total_size_bytes: int|null, failures: list<string>|null}
     */
    private function describe(mixed $status, CarbonImmutable $now): array
    {
        $destination = $this->attempt(fn () => $status->backupDestination());
        $newest = $this->attempt(fn () => $destination?->newestBackup()?->date());
        $lastSuccessfulAt = $newest instanceof \DateTimeInterface
            ? CarbonImmutable::instance($newest)->utc()
            : null;
        $size = $this->attempt(fn () => $destination?->usedStorage());
        $count = $this->attempt(fn () => $destination?->backups()->count());

        return [
            'disk' => $this->string($this->attempt(fn () => $destination?->diskName())),
            'backup_name' => $this->string($this->attempt(fn () => $destination?->backupName())),
            // A `null` verdict is a technical failure to read, never "unhealthy":
            // an unreachable disk is already `false` through the IsReachable check.
            'healthy' => $this->attempt(fn () => (bool) $status->isHealthy()),
            'last_successful_at' => $lastSuccessfulAt?->toIso8601ZuluString(),
            'newest_age_seconds' => $lastSuccessfulAt === null
                ? null
                : max(0, $now->getTimestamp() - $lastSuccessfulAt->getTimestamp()),
            'count' => is_numeric($count) ? (int) $count : null,
            // `BackupCollection::size()` returns a float; `usedStorage()` is the
            // documented accessor.
            'total_size_bytes' => is_numeric($size) ? (int) $size : null,
            'failures' => $this->failures($status),
        ];
    }

    /**
     * Only the `check` of a failure leaves. A `message` is the `getMessage()` of a
     * health check exception and may carry a disk path or a bucket identifier.
     *
     * Absent from v8, which only exposes `getHealthCheckFailure()` in the
     * singular: the field then reports `null` rather than a second, unverified
     * read — and the section is not degraded for it, since `healthy` is the
     * observation and `failures` only its detail.
     *
     * @return list<string>|null
     */
    private function failures(mixed $status): ?array
    {
        if (! is_object($status) || ! method_exists($status, 'failureMessages')) {
            return null;
        }

        $failures = $this->attempt(fn () => $status->failureMessages()->pluck('check')->all());

        if (! is_array($failures)) {
            return null;
        }

        return array_values(array_map(
            fn (mixed $check): string => is_object($check) ? $check::class : (string) $check,
            $failures
        ));
    }

    /**
     * @param  list<array{healthy: bool|null, ...}>  $destinations
     */
    private function status(array $destinations): Status
    {
        $status = Status::Ok;

        foreach ($destinations as $destination) {
            if ($destination['healthy'] === false) {
                return Status::Critical;
            }

            if ($destination['healthy'] === null) {
                $status = Status::Warning;
            }
        }

        return $status;
    }

    /**
     * A destination that cannot be read costs its own facts, never the section.
     */
    private function attempt(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
