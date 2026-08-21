<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Ultraviolettes\Constellation\Events\SnapshotBuilt;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;
use Ultraviolettes\Constellation\Tests\Doubles\AlphaCollector;
use Ultraviolettes\Constellation\Tests\Doubles\BetaCollector;
use Ultraviolettes\Constellation\Tests\Doubles\ExplodingCollector;
use Ultraviolettes\Constellation\Tests\Doubles\TestCollector;
use Ultraviolettes\Constellation\Tests\Doubles\UnavailableCollector;

beforeEach(function () {
    TestCollector::reset();
    config()->set('constellation.token', 'builder-token');
});

function buildPayload(): array
{
    return json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR);
}

it('keeps collecting the other sections when one collector blows up', function () {
    Exceptions::fake();
    config()->set('constellation.collectors', [
        ExplodingCollector::class,
        AlphaCollector::class,
        BetaCollector::class,
    ]);

    $sections = buildPayload()['sections'];

    expect($sections['exploding'])->toMatchArray([
        'status' => 'unavailable',
        'quality' => 'full',
        'reason' => 'collector_exception',
        'collected_at' => null,
    ])
        ->and($sections['alpha']['status'])->toBe('ok')
        ->and($sections['beta']['status'])->toBe('warning')
        ->and(TestCollector::$calls)->toBe(['exploding', 'alpha', 'beta']);

    Exceptions::assertReported(RuntimeException::class);
});

it('reports the exception rather than swallowing it silently', function () {
    Exceptions::fake();
    config()->set('constellation.collectors', [ExplodingCollector::class]);

    buildPayload();

    Exceptions::assertReported(
        fn (RuntimeException $exception) => $exception->getMessage() === 'the client database is on fire'
    );
});

it('turns a CollectorUnavailable into that collector own reason', function () {
    Exceptions::fake();
    config()->set('constellation.collectors', [UnavailableCollector::class]);

    expect(buildPayload()['sections']['unreachable'])->toMatchArray([
        'status' => 'unavailable',
        'reason' => 'queue_monitor_table_missing',
    ]);

    Exceptions::assertNothingReported();
});

it('keys the sections by key() in config order', function () {
    config()->set('constellation.collectors', [BetaCollector::class, AlphaCollector::class]);

    expect(array_keys(buildPayload()['sections']))->toBe(['beta', 'alpha']);
});

it('passes the resolved window to every collector instead of letting them recompute it', function () {
    config()->set('constellation.collectors', [AlphaCollector::class]);

    $payload = buildPayload();

    expect(TestCollector::$window)->not->toBeNull()
        ->and(TestCollector::$window->toArray())->toBe($payload['window']);
});

it('reads the clock once for the whole snapshot', function () {
    config()->set('constellation.collectors', [AlphaCollector::class]);

    $payload = buildPayload();

    expect($payload['sent_at'])->toBe($payload['window']['to'])
        ->and($payload['app']['server_time'])->toBe($payload['sent_at']);
});

it('never advances the cursor from the builder', function () {
    useCursorCache();
    storeCursor('2026-08-17T11:00:00Z');

    app(SnapshotBuilder::class)->build();
    app(SnapshotBuilder::class)->build();

    expect(readCursor())->toBe([
        'window_to' => '2026-08-17T11:00:00Z',
        'sent_at' => '2026-08-17T11:00:00Z',
    ]);
});

it('builds without touching the network', function () {
    Http::fake();

    app(SnapshotBuilder::class)->build();

    Http::assertNothingSent();
});

it('announces the snapshot it built', function () {
    Event::fake([SnapshotBuilt::class]);

    $snapshot = app(SnapshotBuilder::class)->build();

    Event::assertDispatched(
        SnapshotBuilt::class,
        fn (SnapshotBuilt $event) => $event->snapshot->idempotencyKey === $snapshot->idempotencyKey
    );
});

it('encodes an empty sections map as an object', function () {
    config()->set('constellation.collectors', []);

    expect(app(SnapshotBuilder::class)->build()->body)->toContain('"sections":{}');
});

it('encodes an empty section data as an object', function () {
    config()->set('constellation.collectors', [BetaCollector::class]);

    expect(app(SnapshotBuilder::class)->build()->body)->toContain('"data":{}');
});

it('sends the window bounds the resolver decided', function () {
    useCursorCache();
    $cursor = CarbonImmutable::now('UTC')->subHours(3)->toIso8601ZuluString();
    storeCursor($cursor);

    $payload = buildPayload();

    expect($payload['window']['from'])->toBe($cursor)
        ->and($payload['window'])->toHaveKeys(['from', 'to'])
        ->and($payload['window'])->toHaveCount(2);
});

it('carries the resolved window into the snapshot the job will send', function () {
    $snapshot = app(SnapshotBuilder::class)->build();

    expect($snapshot->windowTo())->toBe($snapshot->window->to->toIso8601ZuluString())
        ->and($snapshot->payload['window']['to'])->toBe($snapshot->windowTo());
});

it('states the schema version the ingest endpoint validates against', function () {
    expect(buildPayload()['schema'])->toBe(1);
});

it('does not read the cursor cache from the collectors', function () {
    expect(app(WindowResolver::class)->key())->toStartWith('constellation:cursor:');
});
