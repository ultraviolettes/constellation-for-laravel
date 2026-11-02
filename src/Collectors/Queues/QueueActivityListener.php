<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Throwable;

/**
 * Feeds `QueueActivity` from the two queue events every supported Laravel emits
 * with the same fields — `JobQueued::$connectionName`, `$queue` and `$delay`
 * exist as early as 11.0.0, and `JobProcessing` carries the `Job` contract whose
 * `getQueue()` has always been there.
 *
 * Both handlers run inside the client's own code path — a dispatch in a web
 * request, a job about to start in their worker — so neither may ever throw.
 */
final class QueueActivityListener
{
    public function __construct(private readonly QueueActivity $activity) {}

    public function queued(JobQueued $event): void
    {
        try {
            // A delayed job says nothing about when a worker should have taken
            // it before it is due. Counting it would turn an idle queue holding
            // tomorrow's reminder into a dead worker.
            if ($this->delayed(is_numeric($event->delay) ? (int) $event->delay : null)) {
                return;
            }

            $this->activity->queued(
                (string) $event->connectionName,
                is_string($event->queue) ? $event->queue : null,
                CarbonImmutable::now()->getTimestamp(),
            );
        } catch (Throwable) {
            // Never in the way of the client's dispatch.
        }
    }

    public function processing(JobProcessing $event): void
    {
        try {
            $this->activity->started(
                (string) $event->connectionName,
                $event->job->getQueue(),
                CarbonImmutable::now()->getTimestamp(),
            );
        } catch (Throwable) {
            // Never in the way of the client's job.
        }
    }

    /**
     * `Queue::raiseJobQueuedEvent()` hands the event `secondsUntil($delay)`,
     * from 11.0.0 to 13.x: a date or an interval has become an int by then.
     */
    private function delayed(?int $delay): bool
    {
        return $delay !== null && $delay > 0;
    }
}
