<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Ultraviolettes\Constellation\Events\SnapshotRejected;
use Ultraviolettes\Constellation\Events\SnapshotSent;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;

beforeEach(function () {
    config()->set('constellation.token', 'secret-bearer-token');
    config()->set('constellation.endpoint', 'https://app.constellation.test/api/v1/snapshots');
});

it('advances the cursor only on a 202', function () {
    Http::fake([
        '*' => Http::response(['status' => 'accepted'], 202),
    ]);
    Event::fake([SnapshotSent::class]);

    dispatch_sync(new SendSnapshot('{"schema":1}', 'e6b1a5a0-0000-4000-8000-000000000000', '2026-08-17T14:00:00Z'));

    expect(cache()->get(app(WindowResolver::class)->key()))
        ->toMatchArray(['window_to' => '2026-08-17T14:00:00Z']);

    Event::assertDispatched(SnapshotSent::class);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer secret-bearer-token')
            && $request->hasHeader('Idempotency-Key', 'e6b1a5a0-0000-4000-8000-000000000000')
            && str_starts_with($request->header('User-Agent')[0], 'constellation-for-laravel/');
    });
});

it('does not advance the cursor on a definitive rejection', function () {
    Http::fake([
        '*' => Http::response(['error' => 'unauthenticated'], 401),
    ]);
    Event::fake([SnapshotRejected::class]);

    dispatch_sync(new SendSnapshot('{"schema":1}', 'e6b1a5a0-0000-4000-8000-000000000000', '2026-08-17T14:00:00Z'));

    expect(cache()->get(app(WindowResolver::class)->key()))->toBeNull();

    Event::assertDispatched(SnapshotRejected::class, fn (SnapshotRejected $event) => $event->status === 401);
    Http::assertSentCount(1);
});

it('never carries the token in the serialised job payload', function () {
    $serialised = serialize(new SendSnapshot('{"schema":1}', 'e6b1a5a0-0000-4000-8000-000000000000', '2026-08-17T14:00:00Z'));

    expect($serialised)->not->toContain('secret-bearer-token');
});
