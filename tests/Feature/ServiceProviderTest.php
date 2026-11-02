<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Ultraviolettes\Constellation\ConstellationServiceProvider;
use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;

afterEach(function () {
    if (file_exists(config_path('constellation.php'))) {
        unlink(config_path('constellation.php'));
    }
});

function scheduledEvents(): array
{
    app()->forgetInstance(Schedule::class);

    return app(Schedule::class)->events();
}

function constellationEvent(): ?Event
{
    foreach (scheduledEvents() as $event) {
        if (str_contains((string) $event->command, 'monitor:send')) {
            return $event;
        }
    }

    return null;
}

it('publishes the config under the constellation-config tag', function () {
    expect(config_path('constellation.php'))->not->toBeFile();

    $this->artisan('vendor:publish', ['--tag' => 'constellation-config'])->assertSuccessful();

    expect(config_path('constellation.php'))->toBeFile();

    $published = require config_path('constellation.php');

    expect($published)->toHaveKeys([
        'enabled', 'endpoint', 'token', 'schedule', 'queue', 'http', 'window', 'cache', 'collectors', 'jobs',
        'queues', 'schedule_tasks', 'backups', 'app',
    ])
        ->and($published['schedule'])->toHaveKeys(['enabled', 'cron_expression'])
        ->and($published['queues'])->toHaveKeys(['monitor', 'max', 'warning_age_seconds', 'critical_age_seconds'])
        ->and($published['schedule_tasks'])->toHaveKeys([
            'grace_seconds', 'missed_cap', 'warning_missed', 'critical_missed', 'send_exec_command',
        ])
        ->and($published['window'])->toHaveKeys(['fallback_minutes', 'max_hours'])
        ->and($published['cache'])->toHaveKeys(['store', 'prefix', 'ttl_days'])
        ->and($published['collectors'])->toHaveCount(5);
});

it('publishes the config file and nothing else', function () {
    $paths = ServiceProvider::pathsToPublish(ConstellationServiceProvider::class);

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('config'.DIRECTORY_SEPARATOR.'constellation.php');
});

it('merges its defaults, with no endpoint until the client sets one', function () {
    expect(config('constellation.endpoint'))->toBeNull()
        ->and(config('constellation.window.max_hours'))->toBe(24)
        ->and(config('constellation.window.fallback_minutes'))->toBe(15)
        ->and(config('constellation.cache.ttl_days'))->toBe(7)
        ->and(config('constellation.cache.prefix'))->toBe('constellation')
        ->and(config('constellation.http.timeout'))->toBe(10)
        ->and(config('constellation.http.connect_timeout'))->toBe(5)
        ->and(config('constellation.jobs.top_groups'))->toBe(10)
        ->and(config('constellation.jobs.max_rows'))->toBe(1000);
});

it('registers the send command', function () {
    expect(Artisan::all())->toHaveKey('monitor:send');
});

it('resolves the registry from the configured collector list', function () {
    expect(app(CollectorRegistry::class))->toBe(app(CollectorRegistry::class));
});

it('schedules the send itself, so the client edits no Kernel', function () {
    $event = constellationEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('honours the cron expression the client configured', function () {
    config()->set('constellation.schedule.cron_expression', '0 * * * *');

    expect(constellationEvent()?->expression)->toBe('0 * * * *');
});

it('registers no scheduled task when the client turned the schedule off', function () {
    config()->set('constellation.schedule.enabled', false);

    expect(constellationEvent())->toBeNull();
});

it('registers no scheduled task when the client turned the package off', function () {
    config()->set('constellation.enabled', false);

    expect(constellationEvent())->toBeNull();
});
