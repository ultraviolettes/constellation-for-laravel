<?php

use Illuminate\Support\Facades\Schema;
use Ultraviolettes\Constellation\Collectors\Jobs\FailedJobsSource;
use Ultraviolettes\Constellation\Collectors\Jobs\QueueMonitorSource;
use Ultraviolettes\Constellation\Collectors\JobsCollector;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;

/**
 * The incident of row 1 of the frozen vector (ADR 003 §5), as mode A stores it
 * in its columns and as mode B finds it inside `failed_jobs.exception`.
 */
const RAW_MESSAGE = "SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal'";

const NORMALISED_MESSAGE = "SQLSTATE[HY000] [<num>] Connection refused to '<str>'";

const VECTOR_SIGNATURE = 'f88a4427bfec617dec0d7b1585fbaa683fea8bc54a8ad56344ae6ad55fd1f873';

function chainedFailure(): string
{
    return "PDOException: SQLSTATE[HY000] [2002] Connection refused in /app/x.php:5\n"
        ."Stack trace:\n#0 {main}\n\n"
        .'Next Illuminate\\Database\\QueryException: '.RAW_MESSAGE." in /app/y.php:7\n"
        ."Stack trace:\n#0 {main}";
}

beforeEach(function () {
    config()->set('constellation.token', 'jobs-token');
});

function jobsSection(): array
{
    return json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR)['sections']['jobs'];
}

it('reports jobs as unavailable when neither source exists', function () {
    $section = jobsSection();

    expect($section['status'])->toBe('unavailable')
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBe(JobsCollector::REASON_NO_SOURCE)
        ->and($section['collected_at'])->toBeNull()
        ->and($section['data'])->toBe([]);
});

it('falls back to failed_jobs and says the plugin is missing', function () {
    useCursorCache();
    createFailedJobsTable();
    insertFailedJob();

    $section = jobsSection();

    expect($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(JobsCollector::REASON_QUEUE_MONITOR_ABSENT)
        ->and($section['data']['source'])->toBe(FailedJobsSource::NAME)
        ->and($section['data']['totals'])->toBe(['processed' => null, 'failed' => 1, 'success_rate' => null]);
});

it('announces the array driver and the missing plugin together, in detection order', function () {
    createFailedJobsTable();
    insertFailedJob();

    expect(jobsSection()['reason'])->toBe('cache_driver_array+queue_monitor_absent');
});

it('switches to mode A as soon as both plugin tables exist', function () {
    useCursorCache();
    createQueueMonitorTables();
    createFailedJobsTable();
    insertQueueMonitor();
    insertQueueMonitor();
    insertQueueMonitor();
    insertQueueMonitor(['failed' => true, 'exception_class' => 'RuntimeException', 'exception_message' => 'boom']);
    insertFailedJob();

    $section = jobsSection();

    expect($section['data']['source'])->toBe(QueueMonitorSource::NAME)
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBeNull()
        ->and($section['data']['totals'])->toBe([
            'processed' => 4,
            'failed' => 1,
            'success_rate' => 0.75,
        ]);
});

it('needs both plugin tables, not just one, before claiming mode A', function () {
    useCursorCache();
    createFailedJobsTable();
    insertFailedJob();

    Schema::create('queue_monitors', function ($table) {
        $table->id();
    });

    expect(jobsSection()['data']['source'])->toBe(FailedJobsSource::NAME);
});

it('recomputes the signature in mode A instead of reading the plugin column', function () {
    useCursorCache();
    createQueueMonitorTables();
    insertQueueMonitor([
        'failed' => true,
        'exception_class' => 'Illuminate\Database\QueryException',
        'exception_message' => RAW_MESSAGE,
        'failure_signature' => 'a-stale-signature-the-sdk-must-ignore',
    ]);

    $group = jobsSection()['data']['failure_groups'][0];

    expect($group['signature'])->toBe(VECTOR_SIGNATURE)
        ->and($group['message'])->toBe(NORMALISED_MESSAGE)
        ->and($group['exception_class'])->toBe('Illuminate\Database\QueryException')
        ->and($group['job_class'])->toBe('App\Jobs\SyncInvoices');
});

it('lands on the very same signature in mode B for the very same incident', function () {
    useCursorCache();
    createFailedJobsTable();
    insertFailedJob(['exception' => chainedFailure()]);

    $group = jobsSection()['data']['failure_groups'][0];

    expect($group['signature'])->toBe(VECTOR_SIGNATURE)
        ->and($group['message'])->toBe(NORMALISED_MESSAGE)
        ->and($group['exception_class'])->toBe('Illuminate\Database\QueryException')
        ->and($group['job_class'])->toBe('App\Jobs\SyncInvoices');
});

it('never reads failure_signature nor signature, whatever the mode', function (callable $arrange) {
    useCursorCache();
    $arrange();

    $statements = sqlRunDuring(fn () => jobsSection());

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect($sql)->not->toContain('failure_signature')
            ->and($sql)->not->toContain('signature');
    }
})->with([
    'mode A' => [fn () => fn () => tap(createQueueMonitorTables(), fn () => insertQueueMonitor([
        'failed' => true,
        'exception_class' => 'RuntimeException',
        'exception_message' => 'boom',
        'failure_signature' => 'must-not-be-read',
    ]))],
    'mode B' => [fn () => fn () => tap(createFailedJobsTable(), fn () => insertFailedJob())],
]);

it('groups repeated failures by signature over the window', function () {
    useCursorCache();
    createFailedJobsTable();
    insertFailedJob(['exception' => chainedFailure(), 'failed_at' => now()->subMinutes(3)]);
    insertFailedJob(['exception' => chainedFailure(), 'failed_at' => now()->subMinute()]);
    insertFailedJob();

    $groups = jobsSection()['data']['failure_groups'];

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['signature'])->toBe(VECTOR_SIGNATURE)
        ->and($groups[0]['occurrences'])->toBe(2)
        ->and($groups[0]['first_occurred_at'])->toBeLessThan($groups[0]['last_occurred_at'])
        ->and($groups[1]['occurrences'])->toBe(1);
});

it('keeps only the top groups the config asks for', function () {
    useCursorCache();
    config()->set('constellation.jobs.top_groups', 1);
    createFailedJobsTable();
    insertFailedJob(['exception' => chainedFailure()]);
    insertFailedJob();

    expect(jobsSection()['data']['failure_groups'])->toHaveCount(1);
});

it('announces a read that hit the row guard', function () {
    useCursorCache();
    config()->set('constellation.jobs.max_rows', 1);
    createFailedJobsTable();
    insertFailedJob();
    insertFailedJob();

    $section = jobsSection();

    expect($section['reason'])->toBe(
        JobsCollector::REASON_QUEUE_MONITOR_ABSENT.'+'.JobsCollector::REASON_ROW_LIMIT
    )
        ->and($section['data']['totals']['failed'])->toBe(2)
        ->and($section['data']['failure_groups'][0]['occurrences'])->toBe(1);
});

it('counts only the failures inside the window', function () {
    useCursorCache();
    createFailedJobsTable();
    insertFailedJob(['failed_at' => now()->subHours(2)]);
    insertFailedJob();

    expect(jobsSection()['data']['totals']['failed'])->toBe(1);
});

it('derives the status from the configured thresholds', function (int $failures, string $status) {
    useCursorCache();
    config()->set('constellation.jobs.critical_failures', 3);
    createFailedJobsTable();

    for ($i = 0; $i < $failures; $i++) {
        insertFailedJob();
    }

    expect(jobsSection()['status'])->toBe($status);
})->with([
    'none' => [0, 'ok'],
    'one is already a warning' => [1, 'warning'],
    'below the critical threshold' => [2, 'warning'],
    'at the critical threshold' => [3, 'critical'],
]);

it('reports the window source it was handed', function () {
    createFailedJobsTable();

    expect(jobsSection()['data']['window_source'])->toBe('interval_fallback');
});

it('carries the truncated window degradation into the section', function () {
    useCursorCache();
    storeCursor(now('UTC')->subDays(3)->toIso8601ZuluString());
    createFailedJobsTable();

    $section = jobsSection();

    expect($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe('window_truncated+queue_monitor_absent')
        ->and($section['data']['window_source'])->toBe('cursor_truncated');
});
