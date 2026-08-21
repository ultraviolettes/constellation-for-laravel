<?php

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Ultraviolettes\Constellation\Http\SnapshotClient;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;
use Ultraviolettes\Constellation\Tests\TestCase;

uses(TestCase::class)->in('Feature');

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
    $job->handle(app(SnapshotClient::class), app(WindowResolver::class), app(Dispatcher::class));
}
