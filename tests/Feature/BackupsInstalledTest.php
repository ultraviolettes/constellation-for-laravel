<?php

use Carbon\CarbonImmutable;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Ultraviolettes\Constellation\Collectors\BackupsCollector;
use Ultraviolettes\Constellation\Tests\Doubles\Backups\StatusWithoutFailureMessages;

/**
 * `spatie/laravel-backup` is not a dependency of this package, not even a dev
 * one (T20): what has to be frozen is the shape of the call, and a minimal
 * double states it without pinning a version (R5).
 */
beforeEach(function () {
    installSpatieBackupDoubles();
    BackupDestinationStatusFactory::reset();
    config()->set('constellation.token', 'backups-token');
    config()->set('backup.monitor_backups', []);
});

const BACKUPS_WINDOW_TO = '2026-08-21T14:00:00Z';

function backupsSection(): array
{
    return sectionOf(BackupsCollector::class, windowAt(BACKUPS_WINDOW_TO));
}

function aDestination(array $overrides = []): BackupDestination
{
    return new BackupDestination(
        disk: $overrides['disk'] ?? 's3',
        backupName: $overrides['backupName'] ?? 'acme',
        newest: array_key_exists('newest', $overrides)
            ? $overrides['newest']
            : new Backup(CarbonImmutable::parse('2026-08-21T02:00:00Z')),
        usedStorage: $overrides['usedStorage'] ?? 1073741824.0,
        count: $overrides['count'] ?? 7,
        explodesOn: $overrides['explodesOn'] ?? [],
    );
}

/**
 * @param  list<mixed>  $statuses
 */
function factoryReturns(array $statuses): void
{
    BackupDestinationStatusFactory::$resolver = fn (array $monitor): array => $statuses;
}

it('reports an installed package with no destination as a complete, empty answer', function () {
    $section = backupsSection();

    // The package being absent is an environment cause; having configured no
    // destination is an application state, and the report must be able to
    // affirm it (T22, T25).
    expect($section)->toMatchArray([
        'status' => 'ok',
        'quality' => 'full',
        'reason' => null,
        'collected_at' => BACKUPS_WINDOW_TO,
    ])
        ->and($section['data'])->toBe(['destinations' => []]);
});

it('treats a missing backup config exactly like an empty one', function () {
    config()->offsetUnset('backup');

    expect(backupsSection())->toMatchArray(['status' => 'ok', 'quality' => 'full', 'reason' => null]);
});

it('reads a healthy destination through spatie own verdict', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new BackupDestinationStatus(aDestination())]);

    $section = backupsSection();

    expect($section['status'])->toBe('ok')
        ->and($section['quality'])->toBe('full')
        ->and($section['data']['destinations'][0])->toBe([
            'disk' => 's3',
            'backup_name' => 'acme',
            'healthy' => true,
            'last_successful_at' => '2026-08-21T02:00:00Z',
            'newest_age_seconds' => 43200,
            'count' => 7,
            'total_size_bytes' => 1073741824,
            'failures' => [],
        ]);
});

it('turns an unhealthy destination into the line that sells the maintenance plan', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new BackupDestinationStatus(aDestination(), healthy: false)]);

    $section = backupsSection();

    expect($section['status'])->toBe('critical')
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBeNull()
        ->and($section['data']['destinations'][0]['healthy'])->toBeFalse();
});

it('reports no verdict rather than a second opinion when the health check breaks', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new BackupDestinationStatus(aDestination(), healthExplodes: true)]);

    $section = backupsSection();
    $destination = $section['data']['destinations'][0];

    // Reimplementing the checks would eventually disagree with what the client
    // reads in `backup:monitor` (T23). A null verdict is not "unhealthy".
    expect($destination['healthy'])->toBeNull()
        ->and($destination['disk'])->toBe('s3')
        ->and($destination['count'])->toBe(7)
        ->and($destination['last_successful_at'])->toBe('2026-08-21T02:00:00Z')
        ->and($section['status'])->toBe('warning')
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(BackupsCollector::REASON_HEALTH_UNAVAILABLE);
});

it('keeps the other monitors when one of them cannot be built at all', function () {
    config()->set('backup.monitor_backups', [
        ['name' => 'broken', 'disks' => ['s3']],
        ['name' => 'acme', 'disks' => ['local']],
    ]);

    BackupDestinationStatusFactory::$resolver = function (array $monitor): array {
        if ($monitor['name'] === 'broken') {
            throw new RuntimeException('the destination factory changed its signature');
        }

        return [new BackupDestinationStatus(aDestination(['disk' => 'local']))];
    };

    $section = backupsSection();

    // The try/catch of T23 bounds the damage to a degraded section, never to a
    // lost snapshot (R5).
    expect($section['data']['destinations'])->toHaveCount(1)
        ->and($section['data']['destinations'][0]['disk'])->toBe('local')
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(BackupsCollector::REASON_HEALTH_UNAVAILABLE);
});

it('hands the factory both spellings of the health check key, whichever the client wrote', function (string $written) {
    $checks = ['Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays'];

    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3'], $written => $checks]]);
    factoryReturns([new BackupDestinationStatus(aDestination())]);

    backupsSection();

    // v8 reads `health_checks`, v9 and v10 read `healthChecks`, and the config
    // file published by v10 writes `health_checks`: passing the raw config to
    // v10 would run zero checks and call an unhealthy destination healthy (T21).
    expect(BackupDestinationStatusFactory::$received[0])
        ->toHaveKey('health_checks', $checks)
        ->toHaveKey('healthChecks', $checks)
        ->toHaveKey('name', 'acme');
})->with(['health_checks', 'healthChecks']);

it('sends the name of a failed check and never its message', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new BackupDestinationStatus(
        aDestination(),
        healthy: false,
        failures: [
            ['check' => 'IsReachable', 'message' => 'Could not connect to s3://acme-prod-backups/db'],
            ['check' => 'MaximumAgeInDays', 'message' => 'The newest backup is 9 days old'],
        ],
    )]);

    $section = backupsSection();

    // A failure message is the getMessage() of a health check exception, and
    // carries disk paths and bucket names (§4).
    expect($section['data']['destinations'][0]['failures'])->toBe(['IsReachable', 'MaximumAgeInDays'])
        ->and(json_encode($section))->not->toContain('acme-prod-backups')
        ->and(json_encode($section))->not->toContain('9 days old');
});

it('reports no failure detail on a status that predates failureMessages', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new StatusWithoutFailureMessages(aDestination(), healthy: false)]);

    $section = backupsSection();

    // v8 only exposes `getHealthCheckFailure()`, in the singular: `failures`
    // reports null rather than a second, unverified read — and the section is
    // not degraded for it, since `healthy` is the observation (§4).
    expect($section['data']['destinations'][0]['failures'])->toBeNull()
        ->and($section['data']['destinations'][0]['healthy'])->toBeFalse()
        ->and($section['status'])->toBe('critical')
        ->and($section['quality'])->toBe('full');
});

it('reports a destination that has never been backed up without inventing an age', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new BackupDestinationStatus(aDestination(['newest' => null, 'count' => 0, 'usedStorage' => 0.0]))]);

    $destination = backupsSection()['data']['destinations'][0];

    expect($destination['last_successful_at'])->toBeNull()
        ->and($destination['newest_age_seconds'])->toBeNull()
        ->and($destination['count'])->toBe(0)
        ->and($destination['total_size_bytes'])->toBe(0);
});

it('lists every destination of every monitor', function () {
    config()->set('backup.monitor_backups', [
        ['name' => 'acme', 'disks' => ['s3', 'local']],
        'not-an-array',
    ]);
    factoryReturns([
        new BackupDestinationStatus(aDestination(['disk' => 's3'])),
        new BackupDestinationStatus(aDestination(['disk' => 'local'])),
    ]);

    $section = backupsSection();

    expect(array_column($section['data']['destinations'], 'disk'))->toBe(['s3', 'local'])
        ->and(BackupDestinationStatusFactory::$received)->toHaveCount(1);
});

it('freezes the shape of the backups data block', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);
    factoryReturns([new BackupDestinationStatus(aDestination())]);

    $section = backupsSection();

    expect(array_keys($section['data']))->toBe(['destinations'])
        ->and(array_keys($section['data']['destinations'][0]))->toBe([
            'disk',
            'backup_name',
            'healthy',
            'last_successful_at',
            'newest_age_seconds',
            'count',
            'total_size_bytes',
            'failures',
        ]);
});
