<?php

use Carbon\CarbonImmutable;
use Ultraviolettes\Constellation\Collectors\Jobs\FailedJobsSource;
use Ultraviolettes\Constellation\Collectors\Jobs\QueueMonitorSource;
use Ultraviolettes\Constellation\Collectors\Queues\QueueInventory;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * The queues collector never adds a table and never calls a method the client's
 * Laravel version may not have (ADR 004 T2). What it cannot read, it says.
 */
beforeEach(function () {
    config()->set('constellation.token', 'queues-token');
    // A stock config/queue.php declares every driver Laravel ships with; each
    // test states the connections it is about instead.
    config()->set('queue.default', 'sync');
    config()->set('queue.connections', ['sync' => ['driver' => 'sync']]);
});

const QUEUES_WINDOW_TO = '2026-08-21T14:00:00Z';

function queuesWindow(): Window
{
    return windowAt(QUEUES_WINDOW_TO);
}

function queuesSection(?Window $window = null): array
{
    return sectionOf(QueuesCollector::class, $window ?? queuesWindow());
}

function windowEnd(): CarbonImmutable
{
    return queuesWindow()->to;
}

/**
 * @return list<string>
 */
function queueNames(array $section): array
{
    return array_map(
        fn (array $queue): string => $queue['connection'].':'.$queue['name'],
        $section['data']['queues']
    );
}

it('builds the queue list from the four free sources at once', function () {
    createJobsTable();
    insertJob(['queue' => 'emails', 'available_at' => windowEnd()->getTimestamp() - 30]);
    installHorizonDouble();

    config()->set('queue.default', 'db');
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync'],
        'void' => ['driver' => 'null'],
        'db' => ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs'],
        'redis' => ['driver' => 'redis', 'queue' => 'low'],
    ]);
    config()->set('constellation.queues.monitor', ['redis:high']);
    config()->set('horizon.environments', [
        'production' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['notifications']]],
    ]);

    $inventory = array_map(
        fn (array $queue): string => $queue['connection'].':'.$queue['name'],
        app(QueueInventory::class)->all(windowEnd())
    );

    expect($inventory)->toBe([
        'db:default',       // the connection's own `queue` key
        'redis:low',        // idem
        'redis:high',       // constellation.queues.monitor
        'db:emails',        // a name that exists nowhere but in the jobs table
        'redis:notifications', // horizon's configuration, read and never called
    ]);
});

it('ignores the sync and null connections rather than degrading over them', function () {
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync'],
        'void' => ['driver' => 'null'],
    ]);

    $section = queuesSection();

    // An application running everything inline has no queue to watch: that is a
    // complete observation whose content is empty (T19).
    expect($section)->toMatchArray([
        'status' => 'ok',
        'quality' => 'full',
        'reason' => null,
        'collected_at' => QUEUES_WINDOW_TO,
    ])
        ->and($section['data'])->toBe(['throughput_source' => null, 'queues' => []]);
});

it('reads the age of the oldest pending job from the jobs table when the driver cannot', function () {
    createJobsTable();
    insertJob(['queue' => 'default', 'available_at' => windowEnd()->getTimestamp() - 120]);
    insertJob(['queue' => 'default', 'available_at' => windowEnd()->getTimestamp() - 60]);
    // A job a worker already took, and one due tomorrow: neither is late.
    insertJob(['queue' => 'default', 'available_at' => windowEnd()->getTimestamp() - 900, 'reserved_at' => windowEnd()->getTimestamp()]);
    insertJob(['queue' => 'default', 'available_at' => windowEnd()->getTimestamp() + 86400]);

    config()->set('queue.default', 'db');
    config()->set('queue.connections', ['db' => ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']]);

    // Laravel 11 and 12 < 12.20 hand over a connection without the method (T15).
    fakeQueueDriver('database', ['default' => 4]);

    $section = queuesSection();

    expect($section['data']['queues'][0])->toMatchArray([
        'connection' => 'db',
        'name' => 'default',
        'driver' => 'database',
        'pending' => 4,
        'oldest_pending_age_seconds' => 120,
    ])
        ->and($section['reason'])->not->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED);
});

it('reads the age straight from a connection that answers for itself', function () {
    createJobsTable();
    insertJob(['queue' => 'default', 'available_at' => now()->getTimestamp() - 120]);

    config()->set('queue.default', 'db');
    config()->set('queue.connections', ['db' => ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']]);

    $now = CarbonImmutable::now('UTC');
    $section = queuesSection(new Window($now->subMinutes(15), $now));

    // The real DatabaseQueue of the installed Laravel, method included.
    expect($section['data']['queues'][0]['pending'])->toBe(1)
        ->and($section['data']['queues'][0]['oldest_pending_age_seconds'])->toBeGreaterThanOrEqual(119)
        ->toBeLessThanOrEqual(122);
});

it('reports no age at all rather than inventing one on a driver that cannot answer', function () {
    config()->set('queue.default', 'weird');
    config()->set('queue.connections', ['weird' => ['driver' => 'exotic', 'queue' => 'default']]);

    fakeQueueDriver('exotic', ['default' => 402]);

    $section = queuesSection();

    // Redis before 12.20 does not even carry `createdAt` in the payload: an
    // absent, named field beats a wrong number (T16).
    expect($section['data']['queues'][0]['pending'])->toBe(402)
        ->and($section['data']['queues'][0]['oldest_pending_age_seconds'])->toBeNull()
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED.'exotic')
        ->and($section['status'])->toBe('ok');
});

it('never trusts sqs or beanstalkd for an age, even when they answer', function (string $driver) {
    config()->set('queue.default', 'remote');
    config()->set('queue.connections', ['remote' => ['driver' => $driver, 'queue' => 'default']]);

    // Laravel 13 ships the method on both, hardcoded to null: probing alone
    // would let that null pass for a complete observation.
    fakeQueueDriver($driver, ['default' => 7], windowEnd()->getTimestamp() - 4000);

    $section = queuesSection();

    expect($section['data']['queues'][0]['oldest_pending_age_seconds'])->toBeNull()
        ->and($section['reason'])->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED.$driver)
        ->and($section['status'])->toBe('ok');
})->with(['sqs', 'beanstalkd']);

it('hangs the status on the age of the head, never on the depth of the queue', function (int $age, int $pending, string $status) {
    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);

    fakeQueueDriver('redis', ['default' => $pending], windowEnd()->getTimestamp() - $age);

    $section = queuesSection();

    expect($section['data']['queues'][0]['oldest_pending_age_seconds'])->toBe($age)
        ->and($section['data']['queues'][0]['pending'])->toBe($pending)
        ->and($section['status'])->toBe($status);
})->with([
    // A deep queue that moves is a queue that works (T18).
    'deep and moving' => [10, 50000, 'ok'],
    'just under the warning threshold' => [299, 1, 'ok'],
    'a worker that stopped' => [400, 1, 'warning'],
    'a whole snapshot period late' => [1000, 1, 'critical'],
]);

it('counts throughput and failures per queue from the plugin table', function () {
    createQueueMonitorTables();

    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);
    fakeQueueDriver('redis', ['default' => 3], windowEnd()->getTimestamp() - 5);

    insertQueueMonitor(['queue' => 'default', 'finished_at' => '2026-08-21 13:50:00']);
    insertQueueMonitor(['queue' => 'default', 'finished_at' => '2026-08-21 13:55:00']);
    insertQueueMonitor(['queue' => 'default', 'finished_at' => '2026-08-21 13:56:00', 'failed' => true]);
    // Outside the window on both sides.
    insertQueueMonitor(['queue' => 'default', 'finished_at' => '2026-08-21 13:00:00']);
    insertQueueMonitor(['queue' => 'default', 'finished_at' => '2026-08-21 14:10:00']);

    $section = queuesSection();

    expect($section['data']['throughput_source'])->toBe(QueueMonitorSource::NAME)
        ->and($section['data']['queues'][0]['throughput_window'])->toBe(2)
        ->and($section['data']['queues'][0]['failed_window'])->toBe(1)
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBeNull();
});

it('says the plugin is missing and leaves throughput null rather than guessing', function () {
    createFailedJobsTable();

    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);
    fakeQueueDriver('redis', ['default' => 3], windowEnd()->getTimestamp() - 5);

    insertFailedJob(['connection' => 'store', 'queue' => 'default', 'failed_at' => '2026-08-21 13:50:00']);
    insertFailedJob(['connection' => 'store', 'queue' => 'other', 'failed_at' => '2026-08-21 13:50:00']);

    $section = queuesSection();

    // `failed_jobs` knows failures only: a standard Laravel application has no
    // source at all for the number of jobs it processed (T17).
    expect($section['data']['throughput_source'])->toBe(FailedJobsSource::NAME)
        ->and($section['data']['queues'][0]['throughput_window'])->toBeNull()
        ->and($section['data']['queues'][0]['failed_window'])->toBe(1)
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toContain(QueuesCollector::REASON_MONITOR_ABSENT);
});

it('flags the ambiguity when two watched connections share a queue name', function () {
    createQueueMonitorTables();

    config()->set('queue.default', 'first');
    config()->set('queue.connections', [
        'first' => ['driver' => 'redis', 'queue' => 'default'],
        'second' => ['driver' => 'redis', 'queue' => 'default'],
    ]);
    fakeQueueDriver('redis', ['default' => 1], windowEnd()->getTimestamp() - 5);

    insertQueueMonitor(['queue' => 'default', 'finished_at' => '2026-08-21 13:50:00']);

    $section = queuesSection();

    // `queue_monitors` has no `connection` column: the throughput of one name
    // cannot be split between two connections.
    expect($section['reason'])->toContain(QueuesCollector::REASON_AMBIGUOUS)
        ->and($section['data']['queues'])->toHaveCount(2);
});

it('truncates the list by depth and says it did', function () {
    config()->set('constellation.queues.max', 2);
    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);
    config()->set('constellation.queues.monitor', ['store:low', 'store:high', 'store:mid']);

    fakeQueueDriver('redis', ['default' => 1, 'low' => 5, 'high' => 900, 'mid' => 40], windowEnd()->getTimestamp() - 5);

    $section = queuesSection();

    // A snapshot is a bounded object (§1 of the payload document).
    expect(queueNames($section))->toBe(['store:high', 'store:mid'])
        ->and($section['reason'])->toContain(QueuesCollector::REASON_TRUNCATED);
});

it('reports a connection the application claims but cannot read', function () {
    createQueueMonitorTables();
    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);

    fakeQueueDriver('redis')->explodes = true;

    $section = queuesSection();

    expect($section['data']['queues'])->toBe([])
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(QueuesCollector::REASON_READ_FAILED.'store')
        ->and($section['status'])->toBe('ok');
});

it('says nothing of a stock connection the application never claimed', function () {
    createQueueMonitorTables();
    config()->set('queue.default', 'sync');
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync'],
        'redis' => ['driver' => 'redis', 'queue' => 'default'],
    ]);

    fakeQueueDriver('redis')->explodes = true;

    $section = queuesSection();

    // Every stock config/queue.php declares redis, sqs and beanstalkd; reading
    // one an application never uses always fails, and would degrade every
    // snapshot forever.
    expect($section['data']['queues'])->toBe([])
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBeNull();
});

it('freezes the shape of the queues data block', function () {
    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);
    fakeQueueDriver('redis', ['default' => 12], windowEnd()->getTimestamp() - 41);

    $section = queuesSection();

    expect(array_keys($section['data']))->toBe(['throughput_source', 'queues'])
        ->and(array_keys($section['data']['queues'][0]))->toBe([
            'connection',
            'name',
            'driver',
            'pending',
            'oldest_pending_age_seconds',
            'throughput_window',
            'failed_window',
        ]);
});
