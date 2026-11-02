<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
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

it('ignores a delayed dispatch, which no worker should take before it is due', function () {
    bootConstellation();

    // From 11.0 to 13.x the framework hands JobQueued `secondsUntil($delay)`:
    // a date or an interval given to `->delay()` arrives here as an int.
    fireQueued('store', 'default', stallAt()->getTimestamp(), 3600);

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBeNull();

    fireQueued('store', 'default', stallAt()->getTimestamp(), 0);

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBe(stallAt()->getTimestamp());
});

it('turns a dispatch delayed with a date into seconds before the listener sees it', function () {
    createJobsTable();
    config()->set('queue.connections.db', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs', 'retry_after' => 90]);
    bootConstellation();

    $delays = [];
    app(Dispatcher::class)->listen(JobQueued::class, function (JobQueued $event) use (&$delays): void {
        $delays[] = $event->delay;
    });

    dispatch(fn () => null)->onConnection('db')->delay(now()->addHour());
    dispatch(fn () => null)->onConnection('db')->delay(new DateInterval('PT2H'));

    expect($delays)->each->toBeInt()
        ->and(activityStored('db', 'default', QueueActivity::QUEUED))->toBeNull();
});

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
// Never inside the client's transaction (B1)
// ---------------------------------------------------------------------------

/**
 * @return list<string>
 */
function cacheStatements(array $statements): array
{
    return array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, '"cache"') || str_contains($sql, '`cache`') || str_contains($sql, ' cache ')));
}

function pretendHttpRequest(): void
{
    // Testbench always runs in the console; the HTTP path is the same object
    // with this one answer flipped.
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), false);
}

it('never writes to the cache while the client holds a transaction open', function () {
    bootConstellation();

    $during = [];

    DB::transaction(function () use (&$during): void {
        $during = sqlRunDuring(function (): void {
            fireQueued('store', 'default', CarbonImmutable::now()->getTimestamp());
        });

        expect(app(QueueActivity::class)->hasPending())->toBeTrue();
    });

    // The cache store shares the client's connection here, as the database
    // store does by default: not one statement on it inside the transaction.
    expect(cacheStatements($during))->toBe([])
        ->and(app(QueueActivity::class)->hasPending())->toBeFalse()
        ->and(activityStored('store', 'default', QueueActivity::QUEUED))->toBeInt();
});

it('keeps a rolled back dispatch for the next safe moment rather than writing inside', function () {
    bootConstellation();

    try {
        DB::transaction(function (): void {
            fireQueued('store', 'default', CarbonImmutable::now()->getTimestamp());

            throw new RuntimeException('client rollback');
        });
    } catch (RuntimeException) {
    }

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBeNull()
        ->and(app(QueueActivity::class)->hasPending())->toBeTrue();

    // The process ends (artisan command, worker shutdown): written then.
    app()->terminate();

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBeInt();
});

it('writes nothing during an HTTP request, and everything once it terminates', function () {
    pretendHttpRequest();
    bootConstellation();

    $during = sqlRunDuring(function (): void {
        fireQueued('store', 'default', CarbonImmutable::now()->getTimestamp());
        fireQueued('store', 'emails', CarbonImmutable::now()->getTimestamp());
    });

    expect($during)->toBe([])
        ->and(activityStored('store', 'default', QueueActivity::QUEUED))->toBeNull();

    // After the response is sent.
    app()->terminate();

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBeInt()
        ->and(activityStored('store', 'emails', QueueActivity::QUEUED))->toBeInt();
});

it('throttles across processes by reading what another one stored', function () {
    bootConstellation();
    $now = CarbonImmutable::now()->getTimestamp();

    storeActivity('store', 'default', queued: $now - 30, started: null);

    // A new FPM request: a new instance, with no memory of the last write.
    app()->forgetInstance(QueueActivity::class);

    $writes = sqlRunDuring(fn () => fireQueued('store', 'default', $now));

    expect(array_filter($writes, fn (string $sql): bool => ! str_starts_with(strtolower($sql), 'select')))->toBe([])
        ->and(activityStored('store', 'default', QueueActivity::QUEUED))->toBe($now - 30);

    app()->forgetInstance(QueueActivity::class);
    fireQueued('store', 'default', $now + 31);

    expect(activityStored('store', 'default', QueueActivity::QUEUED))->toBe($now + 31);
});

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------

it('calls a dead worker on a single snapshot and turns the section critical', function () {
    // The worker started its last job two hours ago; jobs kept coming.
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    $section = stallSection();

    // No confirmation on the SDK side: the server opens an incident only on
    // two consecutive critical snapshots (ADR 004 T18 as amended).
    expect($section['data']['queues'][0])->toMatchArray([
        'oldest_pending_age_seconds' => null,
        'last_queued_at' => '2026-08-21T13:59:40Z',
        'last_started_at' => '2026-08-21T12:00:00Z',
        'stalled' => true,
    ])
        ->and($section['status'])->toBe('critical')
        // The head age is still unread: the T16 reserve stays exactly where it was.
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED.'redis');
});

it('never calls an empty queue stalled, whatever the timestamps say', function () {
    // `queue:clear`, or a worker that drained the queue before the throttle let
    // it store its last start: nothing is left for anyone to do.
    fakeQueueDriver('redis', ['default' => 0]);
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-86400)->getTimestamp());

    $section = stallSection();

    expect($section['data']['queues'][0]['stalled'])->toBeFalse()
        ->and($section['status'])->toBe('ok');
});

it('writes nothing when it judges', function () {
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    $writes = sqlRunDuring(fn () => stallSection());

    expect(array_filter($writes, fn (string $sql): bool => ! str_starts_with(strtolower($sql), 'select')))->toBe([]);
});

it('waits the whole threshold without a start before calling anything', function (int $sinceStart, bool $stalled) {
    config()->set('constellation.queues.stalled_after_seconds', 3600);
    storeActivity('store', 'default', queued: stallAt(-10)->getTimestamp(), started: stallAt(-$sinceStart)->getTimestamp());

    expect(stallSection()['data']['queues'][0]['stalled'])->toBe($stalled);
})->with([
    // A long job holding the only worker for 50 minutes is not a dead worker.
    'busy for 50 minutes' => [3000, false],
    'nothing started for an hour' => [3600, true],
]);

it('forgives what the per-minute write throttle can explain', function () {
    // The stored start lags the real one by up to a minute: a dispatch 40 s
    // after it may well have been started already.
    storeActivity('store', 'default', queued: stallAt(-7160)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    expect(stallSection()['data']['queues'][0]['stalled'])->toBeFalse();
});

it('reports a queue whose last start came after its last dispatch as healthy', function () {
    storeActivity('store', 'default', queued: stallAt(-7300)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    // Idle for two hours, nothing new: a quiet queue, not a dead worker.
    expect(stallSection()['data']['queues'][0]['stalled'])->toBeFalse();
});

it('never calls a worker dead when it has never been seen starting a job', function () {
    // Jobs queued since the install, no start ever recorded: a dead worker, or
    // a worker started before the package and never restarted. Undecidable.
    storeActivity('store', 'default', queued: stallAt(-7200)->getTimestamp(), started: null);

    $section = stallSection();

    expect($section['data']['queues'][0])->toMatchArray([
        'last_queued_at' => '2026-08-21T12:00:00Z',
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

    $section = stallSection();

    expect($section['data']['queues'][0]['stalled'])->toBeFalse()
        ->and($section['data']['queues'][0]['oldest_pending_age_seconds'])->toBe(30)
        ->and((string) $section['reason'])->not->toContain(QueuesCollector::REASON_DRIVER_UNSUPPORTED);
});

it('never truncates a stalled queue away', function () {
    config()->set('constellation.queues.max', 2);
    config()->set('constellation.queues.monitor', ['store:low', 'store:high', 'store:mid']);
    fakeQueueDriver('redis', ['default' => 1, 'low' => 5, 'high' => 900, 'mid' => 40]);

    // The shallowest queue is the dead one.
    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    $section = stallSection();

    expect(array_map(fn (array $row): string => $row['name'].':'.json_encode($row['stalled']), $section['data']['queues']))
        ->toBe(['default:true', 'high:false'])
        ->and($section['reason'])->toContain(QueuesCollector::REASON_TRUNCATED)
        ->and($section['status'])->toBe('critical');
});

it('keeps every stalled queue even past the limit', function () {
    config()->set('constellation.queues.max', 1);
    config()->set('constellation.queues.monitor', ['store:high']);
    fakeQueueDriver('redis', ['default' => 1, 'high' => 900]);

    storeActivity('store', 'default', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());
    storeActivity('store', 'high', queued: stallAt(-20)->getTimestamp(), started: stallAt(-7200)->getTimestamp());

    expect(array_column(stallSection()['data']['queues'], 'stalled'))->toBe([true, true]);
});
