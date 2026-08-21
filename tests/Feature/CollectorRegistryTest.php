<?php

use Illuminate\Support\Facades\Http;
use Ultraviolettes\Constellation\Collectors\AppCollector;
use Ultraviolettes\Constellation\Collectors\BackupsCollector;
use Ultraviolettes\Constellation\Collectors\JobsCollector;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Collectors\ScheduleCollector;
use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;
use Ultraviolettes\Constellation\Tests\Doubles\AlphaCollector;
use Ultraviolettes\Constellation\Tests\Doubles\BadKeyCollector;
use Ultraviolettes\Constellation\Tests\Doubles\BetaCollector;
use Ultraviolettes\Constellation\Tests\Doubles\NotACollector;

afterEach(function () {
    BadKeyCollector::$key = 'Not-A-Valid-Key';
});

it('resolves the collectors in config order', function () {
    $keys = array_map(
        fn ($collector) => $collector->key(),
        registryFor([BetaCollector::class, AlphaCollector::class])->all(),
    );

    expect($keys)->toBe(['beta', 'alpha']);
});

it('rejects a duplicate section key', function () {
    $registry = registryFor([AppCollector::class, AppCollector::class]);

    expect(fn () => $registry->all())
        ->toThrow(InvalidArgumentException::class, 'Section key [app] is declared by both');
});

it('fails on a duplicate key at build time, not at send time', function () {
    Http::fake();
    config()->set('constellation.token', 'duplicate-token');
    config()->set('constellation.collectors', [JobsCollector::class, JobsCollector::class]);

    expect(fn () => app(SnapshotBuilder::class)->build())->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('takes the section key from key(), never from the config array key', function () {
    $registry = new CollectorRegistry(app(), ['renamed_by_the_app' => AlphaCollector::class]);

    expect($registry->all()[0]->key())->toBe('alpha');
});

it('rejects a section key the ingest endpoint would silently ignore', function (string $key) {
    BadKeyCollector::$key = $key;

    expect(fn () => registryFor([BadKeyCollector::class])->all())
        ->toThrow(InvalidArgumentException::class);
})->with([
    'dashes and capitals' => ['Not-A-Valid-Key'],
    'capitals' => ['Jobs'],
    'leading digit' => ['1jobs'],
    'leading underscore' => ['_jobs'],
    'empty' => [''],
    'too long' => ['j0123456789012345678901234567890123456789'],
    'trailing space' => ['jobs '],
    'dotted' => ['jobs.failed'],
]);

it('accepts the keys the ingest endpoint stores', function (string $key) {
    BadKeyCollector::$key = $key;

    expect(registryFor([BadKeyCollector::class])->all()[0]->key())->toBe($key);
})->with([
    'single letter' => ['a'],
    'word' => ['jobs'],
    'underscored' => ['failed_jobs_last_hour'],
    'digits' => ['queue2'],
    'forty characters' => ['j012345678901234567890123456789012345678'],
]);

it('refuses a class that is not a collector at all', function () {
    expect(fn () => registryFor([NotACollector::class])->all())
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});

it('registers the five collectors of the J3 scope by default, schedule last', function () {
    expect(config('constellation.collectors'))->toBe([
        AppCollector::class,
        JobsCollector::class,
        QueuesCollector::class,
        BackupsCollector::class,
        // Last on purpose: the only collector whose accuracy depends on
        // listeners registered elsewhere (ADR 004 §6).
        ScheduleCollector::class,
    ]);
});
