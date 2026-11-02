<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\LastDelivery;

/**
 * The "Constellation" section of `php artisan about` (veille, ideas 3 and 4):
 * what support asks first, readable without the dashboard.
 */
beforeEach(function () {
    useCursorCache();
    config()->set('constellation.token', 'cst_live_do-not-print-me');
    config()->set('queue.default', 'store');
    config()->set('queue.connections', ['store' => ['driver' => 'redis', 'queue' => 'default']]);
});

/**
 * @return array<string, mixed>
 */
function aboutJson(): array
{
    Artisan::call('about', ['--only' => 'constellation', '--json' => true]);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['constellation'];
}

function aboutText(): string
{
    Artisan::call('about', ['--only' => 'constellation']);

    return Artisan::output();
}

const ACCEPTED_BODY = '{"schema":1,"sections":{"app":{"status":"ok"},"backups":{"status":"unavailable","reason":"collector_not_installed"},"health":{"status":"unavailable","reason":null}}}';

function acceptSnapshot(string $body = ACCEPTED_BODY): void
{
    Http::fake(['*' => Http::response(['status' => 'accepted'], 202)]);

    $job = new SendSnapshot($body, '9b2f8c51-6c2d-4bb4-8a8e-1f0c2d3e4f5a', CarbonImmutable::now('UTC')->toIso8601ZuluString());
    onAttempt($job, 1);
    handleSendSnapshot($job);
}

it('says whether there is a token and never what it is', function () {
    $text = aboutText();

    expect($text)->toContain('Constellation')
        ->and($text)->toContain('configured')
        ->and($text)->not->toContain('do-not-print-me')
        ->and(json_encode(aboutJson()))->not->toContain('do-not-print-me')
        ->and(aboutJson()['token'])->toBeTrue();

    config()->set('constellation.token', null);

    expect(aboutJson()['token'])->toBeFalse()
        ->and(aboutText())->toContain('MISSING');
});

it('shows the endpoint, the switch, the version and the cache store in use', function () {
    config()->set('constellation.endpoint', 'https://constellation.example/api/v1/snapshots');

    expect(aboutJson())->toMatchArray([
        'version' => '0.1.0',
        'enabled' => true,
        'endpoint' => 'https://constellation.example/api/v1/snapshots',
        'cache_store' => ['store' => 'cursor', 'driver' => 'database'],
    ]);
});

it('warns that nothing survives on the array cache driver', function () {
    config()->set('constellation.cache.store', 'array');

    expect(aboutJson()['cache_store'])->toBe(['store' => 'array', 'driver' => 'array'])
        ->and(aboutText())->toContain('nothing survives between processes');
});

it('reports the last accepted snapshot and the collectors that could not look', function () {
    // Close to the real clock: the database cache store expires on it.
    $sentAt = CarbonImmutable::now('UTC')->startOfSecond()->subMinutes(3);
    CarbonImmutable::setTestNow($sentAt);
    acceptSnapshot();
    CarbonImmutable::setTestNow();

    expect(aboutJson())->toMatchArray([
        'last_successful_send' => $sentAt->toIso8601ZuluString(),
        'unavailable_collectors' => ['backups' => 'collector_not_installed', 'health' => ''],
    ])
        ->and(aboutText())->toContain($sentAt->format('Y-m-d H:i:s').' UTC (3 minutes ago)')
        ->and(aboutText())->toContain('backups (collector_not_installed), health');
});

it('records nothing for a rejected snapshot', function () {
    Http::fake(['*' => Http::response(['error' => 'unauthenticated'], 401)]);

    $job = new SendSnapshot(ACCEPTED_BODY, '9b2f8c51-6c2d-4bb4-8a8e-1f0c2d3e4f5a', CarbonImmutable::now('UTC')->toIso8601ZuluString());
    onAttempt($job, 1);
    handleSendSnapshot($job);

    expect(app(LastDelivery::class)->read())->toBeNull()
        ->and(aboutJson()['last_successful_send'])->toBeNull()
        ->and(aboutText())->toContain('never');
});

it('tells none from unknown', function () {
    acceptSnapshot('{"schema":1,"sections":{"app":{"status":"ok"}}}');

    expect(aboutJson()['unavailable_collectors'])->toBe([]);

    cursorStore()->forget(app(LastDelivery::class)->key());
    // A cursor left by a version that did not record deliveries still dates the send.
    storeCursor('2026-08-21T13:45:00Z');

    expect(aboutJson())->toMatchArray([
        'last_successful_send' => '2026-08-21T13:45:00Z',
        'unavailable_collectors' => null,
    ]);
});

it('asks to restart the workers when jobs are queued and no start was ever seen', function () {
    $activity = app(QueueActivity::class);

    expect(aboutJson()['queue_workers'])->toBe('idle');

    cursorStore()->put($activity->key('store', 'default', QueueActivity::QUEUED), time(), 3600);

    // A worker booted before the package has no listener: its starts are invisible.
    expect(aboutJson()['queue_workers'])->toBe('restart_needed')
        ->and(aboutText())->toContain('store:default')
        ->and(aboutText())->toContain('queue:restart');

    cursorStore()->put($activity->key('store', 'default', QueueActivity::STARTED), time(), 3600);

    expect(aboutJson()['queue_workers'])->toBe('observed');
});

it('does not pretend to watch the workers when nothing records them', function () {
    config()->set('constellation.enabled', false);

    expect(aboutJson())->toMatchArray(['enabled' => false, 'queue_workers' => 'not_watched']);
});

it('still answers when the cache is down', function () {
    Schema::drop('cache');

    expect(aboutJson())->toMatchArray([
        'last_successful_send' => null,
        'unavailable_collectors' => null,
        'queue_workers' => 'unknown',
    ]);
});
