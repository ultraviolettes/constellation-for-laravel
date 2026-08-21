<?php

use Illuminate\Console\Scheduling\Schedule;
use Ultraviolettes\Constellation\Collectors\AppCollector;
use Ultraviolettes\Constellation\Collectors\BackupsCollector;
use Ultraviolettes\Constellation\Collectors\JobsCollector;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Collectors\ScheduleCollector;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;

/**
 * The rule the whole J3 hangs on (ADR 004 T24, T25): `unavailable` answers for a
 * cause of the *environment*, never for a state of the *application*. An empty
 * application is something the report must be able to affirm — "you have no
 * backup configured" is the sentence that sells the plan.
 *
 * The absent-package case of `backups` lives in `BackupsAbsentTest`: a declared
 * class cannot be undeclared, so it needs its own process order.
 */
beforeEach(function () {
    config()->set('constellation.token', 'rule-token');
});

/**
 * An application that is complete but empty: every source the collectors read
 * exists, and every one of them is empty.
 */
function emptyButHealthyApplication(): void
{
    useCursorCache();
    createQueueMonitorTables();
    createFailedJobsTable();
    installSpatieBackupDoubles();
    // The migration repository exists and has nothing pending: another empty
    // source that the `app` section reads as a complete answer.
    test()->artisan('migrate:install');

    config()->set('backup.monitor_backups', []);
    config()->set('queue.default', 'sync');
    config()->set('queue.connections', ['sync' => ['driver' => 'sync']]);

    emptySchedule();
}

function sections(): array
{
    return json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR)['sections'];
}

it('answers an empty application with five affirmations, and not one unavailable', function () {
    emptyButHealthyApplication();

    $sections = sections();

    expect(array_keys($sections))->toBe(['app', 'jobs', 'queues', 'backups', 'schedule']);

    foreach ($sections as $key => $section) {
        // No scheduled task, no queue, no backup destination, no failure
        // history: four observations, four complete answers (T25).
        expect($section['status'])->toBe('ok', "section [{$key}]")
            ->and($section['quality'])->toBe('full', "section [{$key}]")
            ->and($section['reason'])->toBeNull()
            ->and($section['collected_at'])->not->toBeNull();
    }
});

it('never lets a collector call an empty application unavailable', function (string $collector) {
    emptyButHealthyApplication();

    expect(sectionOf($collector)['status'])->not->toBe('unavailable');
})->with([
    AppCollector::class,
    JobsCollector::class,
    QueuesCollector::class,
    BackupsCollector::class,
    ScheduleCollector::class,
]);

it('says unavailable when there is genuinely no source to read', function () {
    // No `queue_monitors`, no `failed_jobs`: the application's failure history
    // has no source at all, which is a fact about its environment (ADR 003).
    $section = sectionOf(JobsCollector::class);

    expect($section['status'])->toBe('unavailable')
        ->and($section['reason'])->toBe(JobsCollector::REASON_NO_SOURCE);
});

it('says unavailable when the process has no scheduler at all', function () {
    unset(app()[Schedule::class]);

    $section = sectionOf(ScheduleCollector::class);

    // Resolving an unbound Schedule would build an empty one and report "no
    // scheduled task" from a process that simply has no scheduler (R1).
    expect($section['status'])->toBe('unavailable')
        ->and($section['reason'])->toBe(ScheduleCollector::REASON_NOT_DEFINED);
});

it('gives every unavailable section the same empty shape', function (string $collector, string $reason) {
    unset(app()[Schedule::class]);

    $section = sectionOf($collector);

    expect($section)->toBe([
        'status' => 'unavailable',
        'quality' => 'degraded',
        'reason' => $reason,
        'collected_at' => null,
        'data' => [],
    ]);
})->with([
    'no failure source' => [JobsCollector::class, JobsCollector::REASON_NO_SOURCE],
    'no scheduler' => [ScheduleCollector::class, ScheduleCollector::REASON_NOT_DEFINED],
]);

it('never reports app or queues as unavailable, whatever the configuration says', function (string $collector) {
    config()->set('queue.default', 'nowhere');
    config()->set('queue.connections', []);
    config()->set('app.url', null);
    config()->set('backup.monitor_backups', null);

    expect(sectionOf($collector)['status'])->not->toBe('unavailable');
})->with([AppCollector::class, QueuesCollector::class]);

it('keeps unavailable to the causes the document names', function () {
    emptyButHealthyApplication();
    unset(app()[Schedule::class]);

    foreach (sections() as $section) {
        if ($section['status'] !== 'unavailable') {
            continue;
        }

        expect($section['reason'])->toBeIn([
            BackupsCollector::REASON_NOT_INSTALLED,
            JobsCollector::REASON_NO_SOURCE,
            ScheduleCollector::REASON_NOT_DEFINED,
            // The safety net of S10, exercised in SnapshotBuilderTest.
            'collector_exception',
        ]);
    }
});
