<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Ultraviolettes\Constellation\Events\SnapshotBuilt;
use Ultraviolettes\Constellation\Events\SnapshotSent;

/**
 * End to end, against a real Constellation server: the snapshot the SDK builds
 * today must be accepted by the SaaS as it is, dead-worker signal included.
 *
 * Skipped unless pointed at a server, and excluded from the default run by
 * phpunit.xml. `bin/e2e` sets everything up — a throwaway environment and its
 * token on a local checkout of the SaaS — runs this test, then checks the rows
 * that landed in `snapshots` and `snapshot_sections`. By hand:
 *
 *     CONSTELLATION_E2E_ENDPOINT=http://127.0.0.1:8004/api/v1/snapshots \
 *     CONSTELLATION_E2E_TOKEN=cnst_ing_… \
 *     vendor/bin/pest --group=e2e
 *
 * `CONSTELLATION_E2E_OUTPUT`, when set, receives the idempotency key of the
 * accepted snapshot so the caller can find it on the server side.
 */
function e2eEnv(string $name): string
{
    // getenv() answers false, not null, for an unset variable.
    return trim((string) getenv($name));
}

beforeEach(function () {
    if (e2eEnv('CONSTELLATION_E2E_ENDPOINT') === '' || e2eEnv('CONSTELLATION_E2E_TOKEN') === '') {
        $this->markTestSkipped('Set CONSTELLATION_E2E_ENDPOINT and CONSTELLATION_E2E_TOKEN, or run bin/e2e.');
    }
});

it('is accepted by the real server, a dead worker on legacy redis included', function () {
    useCursorCache();
    config()->set('constellation.endpoint', e2eEnv('CONSTELLATION_E2E_ENDPOINT'));
    config()->set('constellation.token', e2eEnv('CONSTELLATION_E2E_TOKEN'));

    // Redis as Laravel 11 hands it over: a depth and no head age (T16).
    config()->set('queue.default', 'legacy-redis');
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync'],
        'legacy-redis' => ['driver' => 'redis', 'queue' => 'default'],
    ]);
    fakeQueueDriver('redis', ['default' => 402]);

    bootConstellation();

    $now = CarbonImmutable::now('UTC');

    // The worker started its last job two hours ago; jobs kept being queued.
    // The send job's own queue is that stalled one: it goes out synchronously.
    fireProcessing('legacy-redis', 'default', $now->subHours(2)->getTimestamp());
    fireQueued('legacy-redis', 'default', $now->subMinutes(20)->getTimestamp());

    $built = null;
    $sent = null;
    Event::listen(SnapshotBuilt::class, function (SnapshotBuilt $event) use (&$built): void {
        $built = $event->snapshot;
    });
    Event::listen(SnapshotSent::class, function (SnapshotSent $event) use (&$sent): void {
        $sent = $event;
    });

    // One snapshot says it; the server's two-consecutive rule decides whether
    // it becomes an incident.
    $this->artisan('monitor:send')
        ->expectsOutputToContain('looks blocked')
        ->expectsOutputToContain('Snapshot accepted (202)')
        ->assertSuccessful();

    $queues = $built->payload['sections']['queues'];

    expect($sent)->toBeInstanceOf(SnapshotSent::class)
        ->and($sent->replayed)->toBeFalse()
        ->and($sent->idempotencyKey)->toBe($built->idempotencyKey)
        ->and(array_keys($built->payload['sections']))->toBe(['app', 'jobs', 'queues', 'backups', 'schedule'])
        ->and($queues['status'])->toBe('critical')
        ->and($queues['reason'])->toContain('queue_driver_unsupported:redis')
        ->and($queues['data']['queues'][0])->toMatchArray([
            'connection' => 'legacy-redis',
            'oldest_pending_age_seconds' => null,
            'stalled' => true,
        ]);

    if (e2eEnv('CONSTELLATION_E2E_OUTPUT') !== '') {
        file_put_contents(e2eEnv('CONSTELLATION_E2E_OUTPUT'), json_encode([
            'idempotency_key' => $sent->idempotencyKey,
            'window_to' => $sent->windowTo,
        ], JSON_THROW_ON_ERROR));
    }
})->group('e2e');
