<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskHistory;
use Ultraviolettes\Constellation\ConstellationServiceProvider;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Http\SnapshotClient;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;
use Ultraviolettes\Constellation\Snapshot\LastDelivery;
use Ultraviolettes\Constellation\Snapshot\Window;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;
use Ultraviolettes\Constellation\Tests\Doubles\Queues\AgingQueue;
use Ultraviolettes\Constellation\Tests\Doubles\Queues\FakeQueue;
use Ultraviolettes\Constellation\Tests\Doubles\Queues\FakeQueueConnector;
use Ultraviolettes\Constellation\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'E2e');

/**
 * @param  list<class-string>  $classes
 */
function registryFor(array $classes): CollectorRegistry
{
    return new CollectorRegistry(app(), $classes);
}

/**
 * A cache store that is not an ArrayStore, so the cursor path (S16-S19) can be
 * exercised without the `cache_driver_array` fallback of S20 masking it. Backed
 * by the in-memory SQLite connection: no file, no external service.
 */
function useCursorCache(): void
{
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    config()->set('cache.stores.cursor', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'cache',
        'lock_connection' => null,
        'lock_table' => null,
    ]);

    config()->set('constellation.cache.store', 'cursor');
}

function cursorStore(): CacheRepository
{
    return cache()->store(config('constellation.cache.store'));
}

function storeCursor(string $windowTo): void
{
    cursorStore()->put(
        app(WindowResolver::class)->key(),
        ['window_to' => $windowTo, 'sent_at' => $windowTo],
        604800,
    );
}

function readCursor(): mixed
{
    return cursorStore()->get(app(WindowResolver::class)->key());
}

function createFailedJobsTable(): void
{
    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

/**
 * The plugin's tables, `failure_signature` and `signature` included: they are
 * there precisely so a test can prove the SDK never reads them (S22).
 */
function createQueueMonitorTables(): void
{
    Schema::create('queue_monitors', function (Blueprint $table) {
        $table->id();
        $table->string('job_id')->nullable();
        $table->string('name')->nullable();
        $table->string('queue')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->boolean('failed')->default(false);
        $table->integer('attempt')->default(0);
        $table->integer('progress')->nullable();
        $table->string('exception_class')->nullable();
        $table->longText('exception_message')->nullable();
        $table->string('failure_signature')->nullable();
    });

    Schema::create('queue_monitor_failure_groups', function (Blueprint $table) {
        $table->id();
        $table->string('signature');
        $table->string('exception_class')->nullable();
        $table->integer('occurrences')->default(0);
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertFailedJob(array $attributes = []): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Jobs\SyncInvoices']),
        'exception' => "RuntimeException: Timed out after 30 seconds (attempt 3 of 5) in /app/x.php:5\nStack trace:\n#0 {main}",
        'failed_at' => now(),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertQueueMonitor(array $attributes = []): void
{
    DB::table('queue_monitors')->insert([
        'job_id' => (string) Str::uuid(),
        'name' => 'App\Jobs\SyncInvoices',
        'queue' => 'default',
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
        'failed' => false,
        'attempt' => 1,
        'progress' => 100,
        'exception_class' => null,
        'exception_message' => null,
        'failure_signature' => null,
        ...$attributes,
    ]);
}

/**
 * Collects the SQL of every query run inside the callback.
 *
 * @return list<string>
 */
function sqlRunDuring(callable $callback): array
{
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

/**
 * Attaches a queue job so `attempts()` reports the wanted attempt number, which
 * is what tells a consumed attempt from an abandon (S28).
 */
function onAttempt(SendSnapshot $job, int $attempts): MockInterface
{
    $queueJob = Mockery::spy(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);

    $job->setJob($queueJob);

    return $queueJob;
}

function handleSendSnapshot(SendSnapshot $job): void
{
    $job->handle(app(SnapshotClient::class), app(WindowResolver::class), app(Dispatcher::class), app(LastDelivery::class));
}

/**
 * The single clock read a collector works from, frozen so that every derived
 * duration in a test is arithmetic rather than a race (§6).
 */
function windowAt(string $to = '2026-08-21T14:00:00Z', int $minutes = 15): Window
{
    $end = CarbonImmutable::parse($to)->utc();

    return new Window($end->subMinutes($minutes), $end);
}

/**
 * One collector, in the exact form it takes on the wire.
 *
 * @return array<string, mixed>
 */
function sectionOf(string $collector, ?Window $window = null): array
{
    /** @var Collector $instance */
    $instance = app($collector);

    return json_decode(
        json_encode($instance->collect($window ?? windowAt())->toArray(), JSON_THROW_ON_ERROR),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
}

/**
 * The core `jobs` table, exactly as the framework migration writes it: the SDK
 * reads it, and never ships a migration of its own (S6).
 */
function createJobsTable(): void
{
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertJob(array $attributes = []): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Jobs\SyncInvoices']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
        ...$attributes,
    ]);
}

/**
 * A queue connection whose object is ours, so that the absence of
 * `creationTimeOfOldestPendingJob()` — normal on Laravel 11 and 12 < 12.20 —
 * can be exercised on a vendor that ships it (ADR 004 T2, T15, T16).
 *
 * @param  array<string, int>  $sizes  Queue name to depth.
 */
function fakeQueueDriver(string $driver, array $sizes = [], int|false|null $oldestCreatedAt = false): FakeQueue
{
    $queue = $oldestCreatedAt === false
        ? new FakeQueue($sizes)
        : new AgingQueue($sizes, $oldestCreatedAt);

    Queue::extend($driver, fn (): FakeQueueConnector => new FakeQueueConnector($queue));

    return $queue;
}

/**
 * Minimal doubles of the `spatie/laravel-backup` API, under its own names: the
 * collector probes `class_exists()` on them (T20) and calls them statically.
 *
 * Loaded on demand, never autoloaded: a class cannot be undefined once declared,
 * and `BackupsAbsentTest` needs the process not to know them yet.
 */
function installSpatieBackupDoubles(): void
{
    require_once __DIR__.'/Fixtures/spatie-backup.php';
}

/**
 * Horizon is probed by `class_exists()` and never called: only its config is
 * read (T13). Declaring the name is the whole simulation.
 */
function installHorizonDouble(): void
{
    require_once __DIR__.'/Fixtures/horizon.php';
}

function scheduleCache(): CacheRepository
{
    return cache()->store(config('constellation.cache.store'));
}

/**
 * @param  array<string, int|string|null>  $state
 */
function writeTaskHistory(string $id, array $state): void
{
    scheduleCache()->put(
        app(TaskHistory::class)->taskKey($id),
        array_merge(TaskHistory::EMPTY, $state),
        3600
    );
}

/**
 * @return array<string, mixed>|null
 */
function readTaskHistory(string $id): ?array
{
    return scheduleCache()->get(app(TaskHistory::class)->taskKey($id));
}

function writeObservedSince(CarbonImmutable $at): void
{
    scheduleCache()->put(app(TaskHistory::class)->sinceKey(), $at->toIso8601ZuluString(), 3600);
}

/**
 * A scheduler with nothing but what the test puts in it: the SDK's own task
 * (S31) would otherwise ride along in every inventory.
 */
function emptySchedule(): Schedule
{
    config()->set('constellation.schedule.enabled', false);
    app()->forgetInstance(Schedule::class);

    return app(Schedule::class);
}

/**
 * The listeners are wired at boot, from the config of that moment: booting a
 * fresh provider instance is the honest way to test that decision.
 */
function bootConstellation(): void
{
    app()->register(ConstellationServiceProvider::class, force: true);
}

function fireQueued(string $connection, ?string $queue, int $at, mixed $delay = null): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($at, 'UTC'));

    app(Dispatcher::class)->dispatch(new JobQueued($connection, $queue, 1, 'App\Jobs\Ping', '{}', $delay));

    CarbonImmutable::setTestNow();
}

function fireProcessing(string $connection, ?string $queue, int $at): void
{
    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('getQueue')->andReturn($queue);
    // The framework's own JobProcessing listeners read these.
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\Ping');

    CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($at, 'UTC'));

    app(Dispatcher::class)->dispatch(new JobProcessing($connection, $job));

    CarbonImmutable::setTestNow();
}
