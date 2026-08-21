<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;

beforeEach(function () {
    config()->set('constellation.token', 'test-token');
});

it('builds a snapshot with the frozen payload shape', function () {
    $snapshot = app(SnapshotBuilder::class)->build();
    $payload = json_decode($snapshot->body, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKeys(['schema', 'sent_at', 'window', 'app', 'sections'])
        ->and($payload['schema'])->toBe(1)
        ->and($payload['app'])->toHaveKeys([
            'name', 'environment', 'url', 'php_version', 'laravel_version', 'debug_mode', 'server_time',
        ])
        ->and($payload['sections'])->toHaveKeys(['app', 'jobs'])
        ->and($payload['sections']['app'])->toHaveKeys(['status', 'quality', 'reason', 'collected_at', 'data'])
        ->and($snapshot->idempotencyKey)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('reports jobs as unavailable when no failure source exists', function () {
    $payload = json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['sections']['jobs']['status'])->toBe('unavailable')
        ->and($payload['sections']['jobs']['reason'])->toBe('no_failure_source')
        ->and($payload['sections']['jobs']['data'])->toBe([]);
});

it('falls back to failed_jobs and announces both degradations', function () {
    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Jobs\SyncInvoices']),
        'exception' => "PDOException: SQLSTATE[HY000] [2002] Connection refused in /app/x.php:5\nStack trace:\n#0 {main}\n\nNext Illuminate\Database\QueryException: SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal' in /app/y.php:7\nStack trace:\n#0 {main}",
        'failed_at' => now(),
    ]);

    $section = json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR)['sections']['jobs'];

    expect($section['status'])->toBe('warning')
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe('cache_driver_array+queue_monitor_absent')
        ->and($section['data']['source'])->toBe('failed_jobs')
        ->and($section['data']['totals'])->toBe(['processed' => null, 'failed' => 1, 'success_rate' => null])
        ->and($section['data']['failure_groups'][0]['exception_class'])->toBe('Illuminate\Database\QueryException')
        ->and($section['data']['failure_groups'][0]['job_class'])->toBe('App\Jobs\SyncInvoices')
        ->and($section['data']['failure_groups'][0]['signature'])
        ->toBe('f88a4427bfec617dec0d7b1585fbaa683fea8bc54a8ad56344ae6ad55fd1f873');
});

it('prints the payload without sending it in dry-run', function () {
    $this->artisan('monitor:send --dry-run')->assertSuccessful();

    expect(cache()->get(app(WindowResolver::class)->key()))->toBeNull();
});

it('refuses to run without a token', function () {
    config()->set('constellation.token', null);

    $this->artisan('monitor:send')->assertFailed();
});
