<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
