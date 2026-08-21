<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Ultraviolettes\Constellation\Snapshot\Window;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;

beforeEach(function () {
    config()->set('constellation.token', 'window-token');
    $this->now = CarbonImmutable::parse('2026-08-17T14:00:00Z');
});

it('derives window.from from the cursor and never from now minus the interval', function () {
    useCursorCache();
    storeCursor('2026-08-17T11:00:00Z');

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->from->toIso8601ZuluString())->toBe('2026-08-17T11:00:00Z')
        ->and($window->to->toIso8601ZuluString())->toBe('2026-08-17T14:00:00Z')
        ->and($window->source)->toBe(Window::SOURCE_CURSOR)
        ->and($window->degradations)->toBe([])
        ->and($window->isDegraded())->toBeFalse();
});

it('covers the interval actually elapsed whatever the scheduler cadence', function (string $cursor, int $expectedMinutes) {
    useCursorCache();
    storeCursor($cursor);

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->from->diffInMinutes($window->to))->toBe((float) $expectedMinutes);
})->with([
    'five minutes' => ['2026-08-17T13:55:00Z', 5],
    'fifteen minutes' => ['2026-08-17T13:45:00Z', 15],
    'one hour' => ['2026-08-17T13:00:00Z', 60],
    'a skipped cron catches up' => ['2026-08-17T12:30:00Z', 90],
]);

it('falls back to the configured interval when no cursor exists yet', function () {
    useCursorCache();

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->from->toIso8601ZuluString())->toBe('2026-08-17T13:45:00Z')
        ->and($window->source)->toBe(Window::SOURCE_INTERVAL_FALLBACK)
        ->and($window->degradations)->toBe([]);
});

it('truncates a window older than max_hours and says so', function () {
    useCursorCache();
    storeCursor('2026-07-27T14:00:00Z');

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->from->toIso8601ZuluString())->toBe('2026-08-16T14:00:00Z')
        ->and($window->source)->toBe(Window::SOURCE_CURSOR_TRUNCATED)
        ->and($window->degradations)->toBe([WindowResolver::REASON_TRUNCATED]);
});

it('honours a max_hours the client narrowed', function () {
    useCursorCache();
    config()->set('constellation.window.max_hours', 2);
    storeCursor('2026-08-17T06:00:00Z');

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->from->toIso8601ZuluString())->toBe('2026-08-17T12:00:00Z')
        ->and($window->degradations)->toBe([WindowResolver::REASON_TRUNCATED]);
});

it('degrades on the array driver rather than pretending the window is nominal', function () {
    // The test case runs on the array store by default.
    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->degradations)->toBe([WindowResolver::REASON_ARRAY_DRIVER])
        ->and($window->source)->toBe(Window::SOURCE_INTERVAL_FALLBACK)
        ->and($window->from->toIso8601ZuluString())->toBe('2026-08-17T13:45:00Z')
        ->and($window->isDegraded())->toBeTrue();
});

it('concatenates coexisting degradations in detection order', function () {
    config()->set('cache.default', 'array');
    cache()->put(app(WindowResolver::class)->key(), ['window_to' => '2026-07-01T00:00:00Z'], 600);

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->degradations)
        ->toBe([WindowResolver::REASON_TRUNCATED, WindowResolver::REASON_ARRAY_DRIVER]);
});

it('degrades instead of crashing the scheduler when the cache is unreachable', function () {
    Log::spy();
    config()->set('constellation.cache.store', 'nowhere');

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->degradations)->toBe([WindowResolver::REASON_CACHE_UNAVAILABLE])
        ->and($window->source)->toBe(Window::SOURCE_INTERVAL_FALLBACK);

    Log::shouldHaveReceived('warning')->once();
});

it('ignores a cursor the cache gave back in an unusable shape', function (mixed $stored) {
    useCursorCache();
    cursorStore()->put(app(WindowResolver::class)->key(), $stored, 600);

    $window = app(WindowResolver::class)->resolve($this->now);

    expect($window->source)->toBe(Window::SOURCE_INTERVAL_FALLBACK)
        ->and($window->from->toIso8601ZuluString())->toBe('2026-08-17T13:45:00Z');
})->with([
    'not an array' => ['2026-08-17T13:00:00Z'],
    'missing key' => [['sent_at' => '2026-08-17T13:00:00Z']],
    'not a string' => [['window_to' => 1755439200]],
    'unparsable' => [['window_to' => 'not a date']],
]);

it('writes both bounds of the cursor when it advances', function () {
    useCursorCache();

    app(WindowResolver::class)->advance(
        CarbonImmutable::parse('2026-08-17T14:00:00Z'),
        CarbonImmutable::parse('2026-08-17T14:00:02Z'),
    );

    expect(readCursor())->toBe([
        'window_to' => '2026-08-17T14:00:00Z',
        'sent_at' => '2026-08-17T14:00:02Z',
    ]);
});

it('keys the cursor by a token fingerprint so a repointed .env starts fresh', function () {
    $key = app(WindowResolver::class)->key();

    expect($key)->toBe('constellation:cursor:'.substr(hash('sha256', 'window-token'), 0, 12));

    config()->set('constellation.token', 'another-environment');

    expect(app(WindowResolver::class)->key())->not->toBe($key);
});

it('does not let a cache write failure escape to the client', function () {
    Log::spy();
    config()->set('constellation.cache.store', 'nowhere');

    app(WindowResolver::class)->advance($this->now, $this->now);

    Log::shouldHaveReceived('warning')->once();
});
