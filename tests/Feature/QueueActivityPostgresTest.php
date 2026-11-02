<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;

/**
 * B1, on the database the damage was demonstrated on. With the `database`
 * cache store — Laravel's default — a cache write is an SQL statement on the
 * client's connection: inside their transaction it took a row lock every
 * concurrent dispatch waited on (55P03), and a failing write aborted their
 * whole transaction (25P02) whatever the SDK caught.
 *
 * Needs a throwaway PostgreSQL database; skipped otherwise:
 *
 *     CONSTELLATION_TEST_PGSQL_URL=pgsql://user:secret@127.0.0.1:5432/sdk_pg_test vendor/bin/pest
 */
function pgsqlUrl(): string
{
    return trim((string) getenv('CONSTELLATION_TEST_PGSQL_URL'));
}

beforeEach(function () {
    if (pgsqlUrl() === '') {
        $this->markTestSkipped('Set CONSTELLATION_TEST_PGSQL_URL to a throwaway PostgreSQL database.');
    }

    foreach (['pgsql', 'pgsql_other'] as $name) {
        config()->set('database.connections.'.$name, ['driver' => 'pgsql', 'url' => pgsqlUrl(), 'prefix' => '', 'search_path' => 'public']);
    }

    config()->set('database.default', 'pgsql');
    config()->set('cache.stores.pg', ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'cache', 'lock_connection' => null, 'lock_table' => null]);
    config()->set('cache.stores.pg_other', ['driver' => 'database', 'connection' => 'pgsql_other', 'table' => 'cache', 'lock_connection' => null, 'lock_table' => null]);
    config()->set('constellation.cache.store', 'pg');
    config()->set('constellation.token', 'pg-token');
    config()->set('queue.connections.store', ['driver' => 'redis', 'queue' => 'default']);

    Schema::dropIfExists('cache');
    Schema::dropIfExists('orders');
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('reference');
    });

    bootConstellation();
});

afterEach(function () {
    if (pgsqlUrl() === '') {
        return;
    }

    Schema::dropIfExists('cache');
    Schema::dropIfExists('orders');
    DB::purge('pgsql_other');
});

function createPgCacheTable(): void
{
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
}

it('never aborts the client transaction when the cache table is missing', function () {
    // The cache store is unusable: every write would fail on PostgreSQL.
    DB::transaction(function (): void {
        DB::table('orders')->insert(['reference' => 'A-1']);

        fireQueued('store', 'default', CarbonImmutable::now()->getTimestamp());

        // With the write inside the transaction, this statement failed with
        // 25P02 "current transaction is aborted".
        DB::table('orders')->insert(['reference' => 'A-2']);
    });

    expect(DB::table('orders')->count())->toBe(2);
});

it('holds no lock in the cache table while the client transaction is open', function () {
    createPgCacheTable();
    $key = app(QueueActivity::class)->key('store', 'default', QueueActivity::QUEUED);

    DB::transaction(function () use ($key): void {
        DB::table('orders')->insert(['reference' => 'B-1']);

        fireQueued('store', 'default', CarbonImmutable::now()->getTimestamp());

        // Another request dispatching to the same queue, from its own
        // connection. With the write inside the transaction it waited on the
        // row lock and failed here with 55P03.
        DB::connection('pgsql_other')->statement("SET lock_timeout = '200ms'");
        Cache::store('pg_other')->put($key, 1, 3600);

        expect(DB::connection('pgsql_other')->table('cache')->count())->toBe(1);
    });

    // Written once the client committed, outside their transaction.
    expect(app(QueueActivity::class)->hasPending())->toBeFalse()
        ->and(Cache::store('pg')->get($key))->toBeGreaterThan(1);
});
