<?php

namespace Ultraviolettes\Constellation;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Ultraviolettes\Constellation\Collectors\AppFacts;
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
        }

        $this->registerScheduledSend();
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
