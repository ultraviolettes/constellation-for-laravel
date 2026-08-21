<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ultraviolettes\Constellation\Constellation;
use Ultraviolettes\Constellation\Events\SnapshotRejected;
use Ultraviolettes\Constellation\Events\SnapshotSendFailed;
use Ultraviolettes\Constellation\Events\SnapshotSent;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;

const KEY = 'e6b1a5a0-0000-4000-8000-000000000000';

const WINDOW_TO = '2026-08-17T14:00:00Z';

beforeEach(function () {
    config()->set('constellation.token', 'secret-bearer-token');
    config()->set('constellation.endpoint', 'https://app.constellation.test/api/v1/snapshots');
});

function sendJob(string $body = '{"schema":1}'): SendSnapshot
{
    return new SendSnapshot($body, KEY, WINDOW_TO);
}

it('advances the cursor only on a 202', function () {
    Http::fake(['*' => Http::response(['status' => 'accepted'], 202)]);
    Event::fake([SnapshotSent::class]);

    dispatch_sync(sendJob());

    expect(cache()->get(app(WindowResolver::class)->key()))
        ->toMatchArray(['window_to' => WINDOW_TO]);

    Event::assertDispatched(SnapshotSent::class);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer secret-bearer-token')
            && $request->hasHeader('Idempotency-Key', KEY)
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Content-Type', 'application/json')
            && str_starts_with($request->header('User-Agent')[0], 'constellation-for-laravel/');
    });
});

it('sends the User-Agent the ingest endpoint reads sdk_version from', function () {
    Http::fake(['*' => Http::response([], 202)]);

    dispatch_sync(sendJob());

    Http::assertSent(fn ($request) => $request->header('User-Agent')[0]
        === Constellation::userAgent(app()->version(), PHP_VERSION));
});

it('sends the body untouched', function () {
    Http::fake(['*' => Http::response([], 202)]);

    dispatch_sync(sendJob('{"schema":1,"sent_at":"2026-08-17T14:00:00Z"}'));

    Http::assertSent(fn ($request) => $request->body() === '{"schema":1,"sent_at":"2026-08-17T14:00:00Z"}');
});

it('treats a replayed 202 as a success', function () {
    Http::fake(['*' => Http::response([], 202, ['Idempotency-Replayed' => 'true'])]);
    Event::fake([SnapshotSent::class]);

    dispatch_sync(sendJob());

    Event::assertDispatched(SnapshotSent::class, fn (SnapshotSent $event) => $event->replayed === true);
    expect(cache()->get(app(WindowResolver::class)->key()))->toMatchArray(['window_to' => WINDOW_TO]);
});

it('ends the job on a definitive rejection without a second attempt', function (int $status) {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'nope'], $status)]);
    Event::fake([SnapshotRejected::class, SnapshotSendFailed::class]);

    $job = sendJob();
    $queueJob = onAttempt($job, 1);

    handleSendSnapshot($job);

    $queueJob->shouldNotHaveReceived('release');
    Http::assertSentCount(1);

    expect(cache()->get(app(WindowResolver::class)->key()))->toBeNull();

    Event::assertDispatched(SnapshotRejected::class, fn (SnapshotRejected $event) => $event->status === $status
        && $event->error === 'nope'
        && $event->idempotencyKey === KEY);
    Event::assertNotDispatched(SnapshotSendFailed::class);
})->with([400, 401, 402, 409, 413, 415, 422]);

it('logs an idempotency conflict as an error, since it can only be an SDK bug', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'idempotency_conflict'], 409)]);

    dispatch_sync(sendJob());

    Log::shouldHaveReceived('error')->once();
    Log::shouldNotHaveReceived('warning');
});

it('logs any other rejection as a warning', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'unauthenticated'], 401)]);

    dispatch_sync(sendJob());

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

it('consumes an attempt on a transient failure', function (callable $fake, int $expectedDelay) {
    Http::fake($fake());
    Event::fake([SnapshotRejected::class, SnapshotSendFailed::class]);

    $job = sendJob();
    $queueJob = onAttempt($job, 1);

    handleSendSnapshot($job);

    $queueJob->shouldHaveReceived('release')->once()->with($expectedDelay);

    expect(cache()->get(app(WindowResolver::class)->key()))->toBeNull();

    Event::assertNothingDispatched();
})->with([
    'rate limited without a hint' => [fn () => fn () => Http::response([], 429), 30],
    'rate limited with a short hint' => [fn () => fn () => Http::response([], 429, ['Retry-After' => '12']), 12],
    'rate limited with a long hint capped at a minute' => [fn () => fn () => Http::response([], 429, ['Retry-After' => '3600']), 60],
    'server error' => [fn () => fn () => Http::response([], 500), 30],
    'bad gateway' => [fn () => fn () => Http::response([], 502), 30],
    'network error' => [fn () => fn () => throw new ConnectionException('cURL error 28'), 30],
]);

it('never retries at the HTTP level', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $job = sendJob();
    onAttempt($job, 1);

    handleSendSnapshot($job);

    Http::assertSentCount(1);
});

it('abandons quietly after the last attempt', function () {
    Log::spy();
    Http::fake(['*' => Http::response([], 503)]);
    Event::fake([SnapshotSendFailed::class]);

    $job = sendJob();
    $queueJob = onAttempt($job, 2);

    expect(fn () => handleSendSnapshot($job))->not->toThrow(Throwable::class);

    $queueJob->shouldNotHaveReceived('release');
    Log::shouldHaveReceived('warning')->once();

    Event::assertDispatched(SnapshotSendFailed::class, fn (SnapshotSendFailed $event) => $event->reason === 'http_503'
        && $event->attempts === 2
        && $event->idempotencyKey === KEY);

    expect(cache()->get(app(WindowResolver::class)->key()))->toBeNull();
});

it('abandons quietly after the last attempt on a network error too', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
    Event::fake([SnapshotSendFailed::class]);

    $job = sendJob();
    onAttempt($job, 2);

    expect(fn () => handleSendSnapshot($job))->not->toThrow(Throwable::class);

    Event::assertDispatched(
        SnapshotSendFailed::class,
        fn (SnapshotSendFailed $event) => str_starts_with($event->reason, 'connection_error: ')
    );
});

it('reports an abandon through failed() without throwing', function () {
    Log::spy();
    Event::fake([SnapshotSendFailed::class]);

    $job = sendJob();
    onAttempt($job, 2);

    $job->failed(new RuntimeException('worker killed'));

    Event::assertDispatched(SnapshotSendFailed::class, fn (SnapshotSendFailed $event) => $event->reason === 'job_failed');
    Log::shouldHaveReceived('warning')->once();
});

it('carries no token in any property of the job', function () {
    $job = sendJob();

    $values = [];

    foreach ((new ReflectionObject($job))->getProperties() as $property) {
        expect($property->getName())->not->toContain('token');

        if ($property->isStatic() || ! $property->isInitialized($job)) {
            continue;
        }

        $values[$property->getName()] = $property->getValue($job);
    }

    expect(json_encode($values))->not->toContain('secret-bearer-token');
});

it('never carries the token in the serialised job payload', function () {
    expect(serialize(sendJob()))->not->toContain('secret-bearer-token');
});

it('reads the token from config at call time, not from the job', function () {
    Http::fake(['*' => Http::response([], 202)]);

    $job = sendJob();
    config()->set('constellation.token', 'rotated-token');

    dispatch_sync($job);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer rotated-token'));
});

it('sends the same key and the same body on both attempts of one snapshot', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $snapshot = app(SnapshotBuilder::class)->build();
    $job = new SendSnapshot($snapshot->body, $snapshot->idempotencyKey, $snapshot->windowTo());
    onAttempt($job, 1);

    handleSendSnapshot($job);
    handleSendSnapshot($job);

    $bodies = [];
    $keys = [];

    Http::assertSentCount(2);
    Http::assertSent(function ($request) use (&$bodies, &$keys) {
        $bodies[] = $request->body();
        $keys[] = $request->header('Idempotency-Key')[0];

        return true;
    });

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(array_unique($keys))->toHaveCount(1)
        ->and($keys[0])->toBe($snapshot->idempotencyKey)
        ->and($bodies[0])->toContain('"sent_at":"'.$snapshot->payload['sent_at'].'"');
});

it('queues on the connection and queue the client configured', function () {
    config()->set('constellation.queue.connection', 'monitoring');
    config()->set('constellation.queue.queue', 'low');

    $job = sendJob();

    expect($job->connection)->toBe('monitoring')
        ->and($job->queue)->toBe('low');
});

it('allows exactly two attempts', function () {
    expect(sendJob()->tries)->toBe(2)
        ->and(sendJob()->backoff)->toBe(30)
        ->and(sendJob()->timeout)->toBe(30);
});
