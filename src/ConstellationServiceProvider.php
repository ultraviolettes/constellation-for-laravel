<?php

namespace Ultraviolettes\Constellation;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\ServiceProvider;
use Ultraviolettes\Constellation\Collectors\AppFacts;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivityListener;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskActivityListener;
use Ultraviolettes\Constellation\Console\SendSnapshotCommand;
use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;

final class ConstellationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/constellation.php', 'constellation');

        $this->app->singleton(AppFacts::class);

        // A singleton for its per-process write throttle: one instance per
        // request, per worker, per Octane process.
        $this->app->singleton(QueueActivity::class);

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

        $this->listenToQueueActivity();
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
     * Not console only, unlike the scheduler listeners: `JobQueued` is born
     * wherever the client dispatches, web requests first.
     *
     * Decided once, at boot, so that a disabled package, a missing token or a
     * removed queues collector costs nothing at all — not even a listener call
     * per job. A worker started before the package was installed has none of
     * this until it is restarted, which is why `about` and the README say so.
     */
    private function listenToQueueActivity(): void
    {
        if (! config('constellation.enabled')
            || blank(config('constellation.token'))
            || ! in_array(QueuesCollector::class, (array) config('constellation.collectors', []), true)) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        $events->listen(JobQueued::class, [QueueActivityListener::class, 'queued']);
        $events->listen(JobProcessing::class, [QueueActivityListener::class, 'processing']);
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
