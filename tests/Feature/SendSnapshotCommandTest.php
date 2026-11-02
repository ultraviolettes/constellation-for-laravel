<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskHistory;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;

beforeEach(function () {
    config()->set('constellation.token', 'command-token');
    config()->set('constellation.endpoint', 'https://app.constellation.test/api/v1/snapshots');
});

it('dispatches the standard job when given no option', function () {
    Bus::fake();
    Http::fake();

    $this->artisan('monitor:send')->assertSuccessful();

    Bus::assertDispatched(SendSnapshot::class, fn (SendSnapshot $job) => str_contains($job->body, '"schema":1')
        && $job->idempotencyKey !== ''
        && $job->windowTo !== '');

    Http::assertNothingSent();
});

it('reports the queued window and key to the operator', function () {
    Bus::fake();

    $this->artisan('monitor:send')
        ->expectsOutputToContain('Snapshot queued')
        ->assertSuccessful();
});

it('sends immediately without the queue on --sync', function () {
    Http::fake(['*' => Http::response([], 202)]);

    $this->artisan('monitor:send --sync')
        ->expectsOutputToContain('Snapshot accepted (202)')
        ->assertSuccessful();

    Http::assertSentCount(1);

    expect(cache()->get(app(WindowResolver::class)->key()))->not->toBeNull();
});

it('fails the command when a --sync send is rejected', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'unauthenticated'], 401)]);

    $this->artisan('monitor:send --sync')
        ->expectsOutputToContain('Snapshot rejected with HTTP 401')
        ->assertFailed();

    expect(cache()->get(app(WindowResolver::class)->key()))->toBeNull();
});

it('tells the operator when a --sync send was only released for a second attempt', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $this->artisan('monitor:send --sync')
        ->expectsOutputToContain('released for a second attempt')
        ->assertFailed();
});

it('prints the payload without sending it in dry-run', function () {
    Http::fake();

    $this->artisan('monitor:send --dry-run')
        ->expectsOutputToContain('"schema":1')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('does not move the cursor in dry-run', function () {
    useCursorCache();
    $cursor = now('UTC')->subHour()->toIso8601ZuluString();
    storeCursor($cursor);

    $this->artisan('monitor:send --dry-run')->assertSuccessful();

    expect(readCursor())->toMatchArray(['window_to' => $cursor]);
});

it('does not queue anything in dry-run', function () {
    Bus::fake();

    $this->artisan('monitor:send --dry-run')->assertSuccessful();

    Bus::assertNothingDispatched();
});

it('indents the dry-run payload on --pretty', function () {
    $this->artisan('monitor:send --dry-run --pretty')
        ->expectsOutputToContain('"schema": 1')
        ->assertSuccessful();
});

it('refuses to run without a token', function () {
    Bus::fake();
    config()->set('constellation.token', null);

    $this->artisan('monitor:send')
        ->expectsOutputToContain('No Constellation token')
        ->assertFailed();

    Bus::assertNothingDispatched();
});

it('refuses to run when the client disabled the package', function () {
    Bus::fake();
    config()->set('constellation.enabled', false);

    $this->artisan('monitor:send')
        ->expectsOutputToContain('Constellation is disabled')
        ->assertFailed();

    Bus::assertNothingDispatched();
});

/**
 * C1: with a single worker on `default`, the send job queues exactly where the
 * stall is. The snapshot reporting a dead worker must not wait behind it.
 */
function blockSendQueue(?int $age = null, bool $stalled = true): void
{
    useCursorCache();
    config()->set('queue.default', 'store');
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync'],
        'store' => ['driver' => 'redis', 'queue' => 'default'],
    ]);

    $now = CarbonImmutable::now()->getTimestamp();
    fakeQueueDriver('redis', ['default' => 12], $age === null ? false : $now - $age);

    $activity = app(QueueActivity::class);
    cursorStore()->put($activity->key('store', 'default', QueueActivity::QUEUED), $now - 20, 3600);
    cursorStore()->put($activity->key('store', 'default', QueueActivity::STARTED), $stalled ? $now - 7200 : $now - 5, 3600);
}

it('sends synchronously when the queue the job would take is stalled', function () {
    Bus::fake();
    blockSendQueue();

    $this->artisan('monitor:send')->expectsOutputToContain('looks blocked');

    Bus::assertDispatchedSync(SendSnapshot::class);
    expect(Bus::dispatched(SendSnapshot::class))->toBeEmpty();
});

it('sends synchronously when the queue the job would take holds a job past the critical age', function () {
    Bus::fake();
    blockSendQueue(age: 1000, stalled: false);

    $this->artisan('monitor:send')->expectsOutputToContain('looks blocked');

    Bus::assertDispatchedSync(SendSnapshot::class);
});

it('queues as usual when the stall is on another queue than its own', function () {
    Bus::fake();
    blockSendQueue();
    config()->set('constellation.queue.queue', 'monitoring');

    $this->artisan('monitor:send')->expectsOutputToContain('Snapshot queued')->assertSuccessful();

    Bus::assertDispatched(SendSnapshot::class);
    Bus::assertNotDispatchedSync(SendSnapshot::class);
});

it('queues as usual when its queue is healthy', function () {
    Bus::fake();
    blockSendQueue(age: 30, stalled: false);

    $this->artisan('monitor:send')->assertSuccessful();

    Bus::assertDispatched(SendSnapshot::class);
});

it('writes nothing at all in dry-run', function () {
    useCursorCache();
    emptySchedule()->command('inspire')->hourly();
    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);
    fakeQueueDriver('redis', ['default' => 3]);

    $writes = sqlRunDuring(fn () => $this->artisan('monitor:send', ['--dry-run' => true])->assertSuccessful());

    // Neither the schedule observation base nor anything else: a dry run looks.
    expect(array_values(array_filter($writes, fn (string $sql): bool => ! str_starts_with(strtolower(ltrim($sql)), 'select'))))
        ->toBe([])
        ->and(cursorStore()->get(app(TaskHistory::class)->sinceKey()))->toBeNull();
});

it('refuses to send without a valid endpoint, and dispatches nothing', function (?string $endpoint) {
    Bus::fake();
    config()->set('constellation.endpoint', $endpoint);

    $this->artisan('monitor:send')
        ->expectsOutputToContain('No valid Constellation endpoint')
        ->assertFailed();

    Bus::assertNothingDispatched();
})->with([
    'missing' => null,
    'relative' => '/api/v1/snapshots',
    'plain http to a public host' => 'http://constellation.example/api/v1/snapshots',
]);

it('accepts plain http to a local development host', function (string $endpoint) {
    Bus::fake();
    config()->set('constellation.endpoint', $endpoint);

    $this->artisan('monitor:send')->assertSuccessful();
})->with([
    'http://127.0.0.1:8000/api/v1/snapshots',
    'http://localhost/api/v1/snapshots',
    'http://constellation.test/api/v1/snapshots',
]);

it('still allows a dry run without an endpoint', function () {
    config()->set('constellation.endpoint', null);

    $this->artisan('monitor:send', ['--dry-run' => true])->assertSuccessful();
});
