<?php

namespace Ultraviolettes\Constellation\Collectors\Schedule;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;

/**
 * Records what the scheduler did, one cache key per task (ADR 004 T4).
 *
 * `ScheduledTaskStarting` is deliberately not listened to: it carries no field
 * `Finished` does not, and would double the write volume.
 */
final class TaskActivityListener
{
    public function __construct(private readonly TaskHistory $history) {}

    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;

        // A task blocked by its own mutex still emits `Finished`. Counting it as
        // a run would paint a stuck task green every minute (T7).
        if ($this->overlapped($task)) {
            $this->history->record($task, ['overlapped_at' => time()]);

            return;
        }

        if ($task->runInBackground) {
            // `$runtime` only measures process spawn time here, and the exit code
            // is not known yet: `ScheduledBackgroundTaskFinished` carries it.
            $this->history->record($task, ['ran_at' => time()]);

            return;
        }

        $this->history->record($task, [
            'ran_at' => time(),
            'duration_ms' => (int) round($event->runtime * 1000),
            'exit_code' => is_numeric($task->exitCode) ? (int) $task->exitCode : null,
        ]);
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->history->record($event->task, [
            'exit_code' => is_numeric($event->task->exitCode) ? (int) $event->task->exitCode : null,
        ]);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        // Only the class: an exception message may carry client data (S/§2 rule 4).
        $this->history->record($event->task, [
            'failed_at' => time(),
            'exit_code' => is_numeric($event->task->exitCode) ? (int) $event->task->exitCode : 1,
            'exception_class' => $event->exception::class,
        ]);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->history->record($event->task, ['skipped_at' => time()]);
    }

    /**
     * `Event::run()` returns before `finish()` when the mutex is taken, so the
     * exit code of a foreground task stays null — the only trace it leaves.
     */
    private function overlapped(Event $task): bool
    {
        return $task->exitCode === null && ! $task->runInBackground;
    }
}
