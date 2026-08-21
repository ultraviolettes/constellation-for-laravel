<?php

namespace Ultraviolettes\Constellation;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Ultraviolettes\Constellation\Collectors\AppFacts;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskActivityListener;
use Ultraviolettes\Constellation\Console\SendSnapshotCommand;
use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;

final class ConstellationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/constellation.php', 'constellation');

        $this->app->singleton(AppFacts::class);

        $this->app->singleton(CollectorRegistry::class, fn ($app) => new CollectorRegistry(
            $app,
            array_values((array) config('constellation.collectors', []))
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/constellation.php' => config_path('constellation.php'),
            ], 'constellation-config');

            $this->commands([SendSnapshotCommand::class]);
            $this->listenToScheduledTasks();
        }

        $this->registerScheduledSend();
    }

    /**
     * Console only: scheduler events are born nowhere else, and a web request has
     * no reason to pay for the registration (ADR 004 §2).
     *
     * The event classes are named by `::class`, which PHP resolves at compile
     * time to a plain string: nothing from `illuminate/console` is autoloaded here.
     */
    private function listenToScheduledTasks(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        $events->listen(ScheduledTaskFinished::class, [TaskActivityListener::class, 'finished']);
        $events->listen(ScheduledBackgroundTaskFinished::class, [TaskActivityListener::class, 'backgroundFinished']);
        $events->listen(ScheduledTaskFailed::class, [TaskActivityListener::class, 'failed']);
        $events->listen(ScheduledTaskSkipped::class, [TaskActivityListener::class, 'skipped']);
    }

    /**
     * Registered here rather than in the client's routes/console.php: install
     * friction is the conversion rate (S31).
     */
    private function registerScheduledSend(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('constellation.enabled') || ! config('constellation.schedule.enabled')) {
                return;
            }

            $schedule->command(SendSnapshotCommand::class)
                ->cron((string) config('constellation.schedule.cron_expression', '*/15 * * * *'))
                ->withoutOverlapping();
        });
    }
}
