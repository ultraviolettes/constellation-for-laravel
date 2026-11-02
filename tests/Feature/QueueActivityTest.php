<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * The dead-worker signal of the veille (idea 1), which fills the hole T16 left
 * on Redis and SQS before 12.20: two timestamps per queue in cache, written by
 * the `JobQueued` and `JobProcessing` listeners, judged by the collector.
 */
const STALL_AT = '2026-08-21T14:00:00Z';

beforeEach(function () {
    useCursorCache();
    config()->set('constellation.token', 'stall-token');
    config()->set('queue.default', 'store');
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync'],
        // Redis as Laravel 11 hands it over: `size()` and nothing else.
        'store' => ['driver' => 'redis', 'queue' => 'default'],
    ]);
    fakeQueueDriver('redis', ['default' => 402]);
});

function stallAt(int $offsetSeconds = 0): CarbonImmutable
{
    return CarbonImmutable::parse(STALL_AT)->addSeconds($offsetSeconds);
}

function activityStored(string $connection, string $queue, string $kind): mixed
{
    return cursorStore()->get(app(QueueActivity::class)->key($connection, $queue, $kind));
}

function storeActivity(string $connection, string $queue, ?int $queued, ?int $started): void
{
    $activity = app(QueueActivity::class);

    if ($queued !== null) {
        cursorStore()->put($activity->key($connection, $queue, QueueActivity::QUEUED), $queued, 3600);
    }

    if ($started !== null) {
        cursorStore()->put($activity->key($connection, $queue, QueueActivity::STARTED), $started, 3600);
    }
}

/**
 * @return array<string, mixed>
 */
function stallSection(int $offsetSeconds = 0): array
{
    $to = stallAt($offsetSeconds);

    return sectionOf(QueuesCollector::class, new Window($to->subMinutes(15), $to));
}

// ---------------------------------------------------------------------------
// Listeners
// ---------------------------------------------------------------------------

it('records the last dispatch and the last start of each queue', function () {
    bootConstellation();

    fireQueued('store', 'default', stallAt()->getTimestamp());
    fireProcessing('store', 'default', stallAt(5)->getTimestamp());

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBe(stallAt()->getTimestamp())
        ->and(activityStored('store', 'default', QueueActivity::STARTED))->toBe(stallAt(5)->getTimestamp());
});

it('writes at most once per queue and per minute, whatever the job volume', function () {
    bootConstellation();

    fireProcessing('store', 'default', stallAt()->getTimestamp());

    $writes = sqlRunDuring(function () {
        for ($second = 1; $second < 60; $second++) {
            fireProcessing('store', 'default', stallAt($second)->getTimestamp());
        }
    });

    // A worker draining a thousand jobs a minute costs one cache write.
    expect(array_filter($writes, fn (string $sql): bool => ! str_starts_with(strtolower($sql), 'select')))->toBe([])
        ->and(activityStored('store', 'default', QueueActivity::STARTED))->toBe(stallAt()->getTimestamp());

    fireProcessing('store', 'default', stallAt(60)->getTimestamp());

    expect(activityStored('store', 'default', QueueActivity::STARTED))->toBe(stallAt(60)->getTimestamp());
});

it('registers no listener at all when it would record for nothing', function (array $config) {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    $queued = count(app(Dispatcher::class)->getListeners(JobQueued::class));
    $processing = count(app(Dispatcher::class)->getListeners(JobProcessing::class));

    bootConstellation();

    // Disabled, tokenless or without the queues collector: not even a listener
    // call per job (the dispatcher is the client's hot path).
    expect(app(Dispatcher::class)->getListeners(JobQueued::class))->toHaveCount($queued)
        ->and(app(Dispatcher::class)->getListeners(JobProcessing::class))->toHaveCount($processing);
})->with([
    'disabled' => [['constellation.enabled' => false]],
    'no token' => [['constellation.token' => null]],
    'queues collector removed' => [['constellation.collectors' => [QueuesCollector::class.'Nope']]],
]);

it('ignores sync and null connections, which no worker ever runs', function () {
    config()->set('queue.connections.void', ['driver' => 'null']);
    bootConstellation();

    $writes = sqlRunDuring(function () {
        fireQueued('sync', 'default', stallAt()->getTimestamp());
        fireProcessing('sync', 'default', stallAt()->getTimestamp());
        fireQueued('void', 'default', stallAt()->getTimestamp());
    });

    expect($writes)->toBe([]);
});

it('ignores a delayed dispatch, which no worker should take before it is due', function (mixed $delay) {
    bootConstellation();

    fireQueued('store', 'default', stallAt()->getTimestamp(), $delay);

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBeNull();
})->with([
    'seconds' => [3600],
    'date' => [fn () => stallAt(86400)],
    'interval' => [new DateInterval('PT2H')],
]);

it('files a null queue under the default queue of its connection', function () {
    bootConstellation();

    fireQueued('store', null, stallAt()->getTimestamp());
    fireProcessing('store', null, stallAt(1)->getTimestamp());

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBe(stallAt()->getTimestamp())
        ->and(activityStored('store', 'default', QueueActivity::STARTED))->toBe(stallAt(1)->getTimestamp());
});

it('matches the full URL an SQS job reports with the configured queue name', function (string $configured, string $reported) {
    config()->set('queue.connections.sqs', [
        'driver' => 'sqs',
        'prefix' => 'https://sqs.eu-west-3.amazonaws.com/123456789012',
        'queue' => $configured,
        'suffix' => '-production',
    ]);
    bootConstellation();

    // `JobQueued` carries the name it was dispatched to; `SqsJob::getQueue()`
    // returns the URL `SqsQueue::getQueue()` built from it.
    fireQueued('sqs', null, stallAt()->getTimestamp());
    fireProcessing('sqs', $reported, stallAt(1)->getTimestamp());

    expect(activityStored('sqs', $configured, QueueActivity::STARTED))->toBe(stallAt(1)->getTimestamp())
        ->and(activityStored('sqs', $configured, QueueActivity::QUEUED))->toBe(stallAt()->getTimestamp());
})->with([
    'plain' => ['emails', 'https://sqs.eu-west-3.amazonaws.com/123456789012/emails-production'],
    'already suffixed' => ['emails-production', 'https://sqs.eu-west-3.amazonaws.com/123456789012/emails-production'],
    'fifo' => ['orders.fifo', 'https://sqs.eu-west-3.amazonaws.com/123456789012/orders-production.fifo'],
]);

it('records what a real database queue and a real worker emit', function () {
    createJobsTable();
    config()->set('queue.connections.db', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs', 'retry_after' => 90]);
    bootConstellation();

    dispatch(fn () => null)->onConnection('db')->onQueue('emails');

    expect(activityStored('db', 'emails', QueueActivity::QUEUED))->toBeInt()
        ->and(activityStored('db', 'emails', QueueActivity::STARTED))->toBeNull();

    $this->artisan('queue:work', ['connection' => 'db', '--queue' => 'emails', '--once' => true])->assertSuccessful();

    expect(activityStored('db', 'emails', QueueActivity::STARTED))->toBeInt();
});

it('never gets in the way of a dispatch when the cache is down', function () {
    bootConstellation();
    Schema::drop('cache');
    Log::spy();

    fireQueued('store', 'default', stallAt()->getTimestamp());
    fireQueued('store', 'other', stallAt()->getTimestamp());
    fireProcessing('store', 'default', stallAt()->getTimestamp());

    // Logged once per process, not once per job.
    Log::shouldHaveReceived('warning')->once();
});

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------

it('confirms a dead worker over two snapshots and turns the section critical', function () {
    // The worker started its last job two hours ago; jobs kept coming.
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    $first = stallSection();

    // One sighting is a suspicion, not a verdict: an idle queue that received
    // a job a second ago looks exactly the same through two timestamps.
    expect($first['data']['queues'][0]['stalled'])->toBeFalse()
        ->and($first['status'])->toBe('ok');

    $second = stallSection(900);

    expect($second['data']['queues'][0])->toMatchArray([
        'oldest_pending_age_seconds' => null,
        'last_queued_at' => '2026-08-21T13:59:40Z',
        'last_started_at' => '2026-08-21T12:00:00Z',
        'stalled' => true,
    ])
        ->and($second['status'])->toBe('critical')
        // The head age is still unread: the T16 reserve stays exactly where it was.
        ->and($second['quality'])->toBe('degraded')
        ->and($second['reason'])->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED.'redis');
});

it('clears the suspicion when the worker was only waiting for work', function () {
    // Idle for two hours, then a job a second before the snapshot.
    storeActivity('store', 'default', queued: stallAt(-1)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    expect(stallSection()['data']['queues'][0]['stalled'])->toBeFalse();

    // The worker took it two seconds later.
    storeActivity('store', 'default', queued: null, started: stallAt(1)->getTimestamp());

    $next = stallSection(900);

    expect($next['data']['queues'][0]['stalled'])->toBeFalse()
        ->and($next['status'])->toBe('ok')
        ->and(activityStored('store', 'default', QueueActivity::SUSPECT))->toBeNull();
});

it('does not confirm twice in the same minute', function () {
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    stallSection();

    // `monitor:send --dry-run` run twice in a row is not a second witness.
    expect(stallSection(30)['data']['queues'][0]['stalled'])->toBeFalse()
        ->and(stallSection(60)['data']['queues'][0]['stalled'])->toBeTrue();
});

it('waits the whole threshold without a start before suspecting anything', function (int $sinceStart, bool $stalled) {
    config()->set('constellation.queues.stalled_after_seconds', 3600);
    storeActivity('store', 'default', queued: stallAt(-10)->getTimestamp(), started: stallAt(-$sinceStart)->getTimestamp());

    stallSection();

    expect(stallSection(120)['data']['queues'][0]['stalled'])->toBe($stalled);
})->with([
    // A long job holding the only worker for 50 minutes is not a dead worker.
    'busy for 50 minutes' => [3000 - 120, false],
    'nothing started for an hour' => [3600, true],
]);

it('forgives what the per-minute write throttle can explain', function () {
    // The stored start lags the real one by up to a minute: a dispatch 40 s
    // after it may well have been started already.
    storeActivity('store', 'default', queued: stallAt(-7160)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    stallSection();

    expect(stallSection(900)['data']['queues'][0]['stalled'])->toBeFalse();
});

it('reports a queue whose last start came after its last dispatch as healthy', function () {
    storeActivity('store', 'default', queued: stallAt(-7300)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    stallSection();

    // Idle for two hours, nothing waiting: a quiet queue, not a dead worker.
    expect(stallSection(900)['data']['queues'][0]['stalled'])->toBeFalse();
});

it('never calls a worker dead when it has never been seen starting a job', function () {
    // Jobs queued since the install, no start ever recorded: a dead worker, or
    // a worker started before the package and never restarted. Undecidable.
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: null);

    stallSection();
    $section = stallSection(7200);

    expect($section['data']['queues'][0])->toMatchArray([
        'last_queued_at' => '2026-08-21T13:59:40Z',
        'last_started_at' => null,
        'stalled' => null,
    ])
        ->and($section['status'])->toBe('ok');
});

it('says nothing waits when no dispatch was ever seen', function () {
    $section = stallSection();

    expect($section['data']['queues'][0])->toMatchArray([
        'last_queued_at' => null,
        'last_started_at' => null,
        'stalled' => false,
    ]);
});

it('cannot tell anything on the array cache driver, and says so with null', function () {
    config()->set('constellation.cache.store', 'array');

    $section = stallSection();

    // Nothing written by a worker survives into the collecting process.
    expect($section['data']['queues'][0])->toMatchArray([
        'last_queued_at' => null,
        'last_started_at' => null,
        'stalled' => null,
    ])
        ->and($section['status'])->toBe('ok');
});

it('keeps the snapshot when the activity cannot be read', function () {
    Schema::drop('cache');

    $section = stallSection();

    expect($section['data']['queues'][0]['stalled'])->toBeNull()
        ->and($section['data']['queues'][0]['pending'])->toBe(402);
});

it('adds the stall to what the head age already says, never the reverse', function () {
    // Laravel 12.20+ Redis reads its own head age: the stall is a second witness.
    fakeQueueDriver('redis', ['default' => 3], stallAt(-30)->getTimestamp());
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-10)->getTimestamp());

    stallSection();
    $section = stallSection(120);

    expect($section['data']['queues'][0]['stalled'])->toBeFalse()
        ->and($section['data']['queues'][0]['oldest_pending_age_seconds'])->toBe(150)
        ->and((string) $section['reason'])->not->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED);
});
