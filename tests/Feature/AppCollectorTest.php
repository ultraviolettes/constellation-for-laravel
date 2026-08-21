<?php

use Ultraviolettes\Constellation\Collectors\AppFacts;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;

beforeEach(function () {
    config()->set('constellation.token', 'app-token');
});

function appPayload(): array
{
    return json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR);
}

function withPendingMigration(): void
{
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    test()->artisan('migrate:install');
}

function runPendingMigrations(): void
{
    test()->artisan('migrate', ['--force' => true]);
}

it('reports the runtime versions and the debug flag', function () {
    $data = appPayload()['sections']['app']['data'];

    expect($data['php_version'])->toBe(PHP_VERSION)
        ->and($data['laravel_version'])->toBe(app()->version())
        ->and($data['debug_mode'])->toBeBool()
        ->and($data['name'])->toBe(config('app.name'))
        ->and($data['environment'])->toBe(config('app.env'));
});

it('states the seven identity fields in the root block', function () {
    expect(appPayload()['app'])->toHaveKeys([
        'name', 'environment', 'url', 'php_version', 'laravel_version', 'debug_mode', 'server_time',
    ])->toHaveCount(7);
});

it('says the same thing in the root block and in the section', function () {
    $payload = appPayload();

    foreach (['name', 'environment', 'url', 'php_version', 'laravel_version', 'debug_mode', 'server_time'] as $field) {
        expect($payload['sections']['app']['data'][$field])->toBe($payload['app'][$field]);
    }
});

it('withholds the url when the client asked it to', function () {
    config()->set('constellation.app.send_url', false);

    expect(appPayload()['app']['url'])->toBeNull();
});

it('counts the migrations still to run', function () {
    withPendingMigration();

    $section = appPayload()['sections']['app'];

    expect($section['data']['pending_migrations'])->toBeGreaterThanOrEqual(1)
        ->and($section['status'])->toBe('warning')
        ->and($section['quality'])->toBe('full');
});

it('reports zero once every migration has run', function () {
    withPendingMigration();
    runPendingMigrations();

    $section = appPayload()['sections']['app'];

    expect($section['data']['pending_migrations'])->toBe(0)
        ->and($section['status'])->toBe('ok')
        ->and($section['reason'])->toBeNull();
});

it('says it could not look rather than reporting no pending migration', function () {
    $section = appPayload()['sections']['app'];

    expect($section['data']['pending_migrations'])->toBeNull()
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe('migration_state_unreadable');
});

it('raises debug mode left on in production to critical', function () {
    $this->app['env'] = 'production';
    config()->set('app.env', 'production');
    config()->set('app.debug', true);

    expect(appPayload()['sections']['app']['status'])->toBe('critical');
});

it('leaves debug mode alone outside production', function () {
    config()->set('app.debug', true);
    withPendingMigration();
    runPendingMigrations();

    expect(appPayload()['sections']['app']['status'])->toBe('ok');
});

it('warns about an uncached production config', function () {
    $this->app['env'] = 'production';
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    withPendingMigration();
    runPendingMigrations();

    $section = appPayload()['sections']['app'];

    expect($section['data']['config_cached'])->toBeFalse()
        ->and($section['status'])->toBe('warning');
});

it('reads the facts once for the whole snapshot', function () {
    $facts = app(AppFacts::class);

    expect($facts)->toBe(app(AppFacts::class))
        ->and($facts->all())->toBe($facts->all());
});
