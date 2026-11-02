<?php

namespace Ultraviolettes\Constellation\Collectors;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskHistory;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskIdentity;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * The inventory is read live from the scheduler and never persisted (ADR 004 T3):
 * a task removed from the schedule leaves the section on the very next snapshot.
 * Only the execution history goes through the cache.
 */
final class ScheduleCollector implements Collector
{
    public const REASON_NOT_DEFINED = 'scheduler_not_defined';

    public const REASON_UNNAMED_CLOSURES = 'schedule_unnamed_closures';

    public const REASON_ARRAY_DRIVER = 'cache_driver_array';

    public const REASON_CACHE_UNAVAILABLE = 'cache_unavailable';

    public function __construct(
        private readonly Container $container,
        private readonly TaskHistory $history,
        private readonly Config $config,
    ) {}

    public function key(): string
    {
        return 'schedule';
    }

    public function collect(Window $window): Section
    {
        // Resolving an unbound Schedule would build an empty one and report
        // "no scheduled tasks" from a process that simply has no scheduler (R1).
        if (! $this->container->bound(Schedule::class)) {
            return Section::unavailable($this->key(), self::REASON_NOT_DEFINED);
        }

        $now = $window->to;
        $schedule = $this->container->make(Schedule::class);

        /** @var array<string, Event> $inventory */
        $inventory = [];
        $unnamedCollision = false;

        foreach ($schedule->events() as $event) {
            $id = TaskIdentity::of($event);

            if (isset($inventory[$id])) {
                // Two identical `command()` calls merge silently (R7); two
                // anonymous closures are indistinguishable and must be said so.
                $unnamedCollision = $unnamedCollision || TaskIdentity::isAnonymousClosure($event);

                continue;
            }

            $inventory[$id] = $event;
        }

        $history = $this->history($now, array_keys($inventory), ! $window->readOnly);
        $limit = $now->subSeconds((int) $this->config->get('constellation.schedule_tasks.grace_seconds', 90));
        $cap = (int) $this->config->get('constellation.schedule_tasks.missed_cap', 100);
        $sendExec = (bool) $this->config->get('constellation.schedule_tasks.send_exec_command', false);
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        $tasks = [];
        $status = Status::Ok;

        foreach ($inventory as $id => $event) {
            $state = $history['states'][$id];

            // Skipped and overlapped both mean the scheduler *did* come by: it
            // declined on purpose, or found the mutex taken. Neither is a miss.
            $base = max(
                $history['since']->getTimestamp(),
                $state['ran_at'] ?? 0,
                $state['skipped_at'] ?? 0,
                $state['overlapped_at'] ?? 0,
            );

            // A task with no history at all, on a snapshot that just created the
            // observation base, has no window to count misses in yet (T11).
            $blind = $history['blind']
                && $state['ran_at'] === null
                && $state['skipped_at'] === null
                && $state['overlapped_at'] === null;

            [$missed, $capped] = $blind
                ? [null, false]
                : $this->missed($event, CarbonImmutable::createFromTimestamp($base, 'UTC'), $limit, $cap);

            $tasks[] = [
                'id' => $id,
                'command' => TaskIdentity::published($event, $sendExec),
                'expression' => $event->getExpression(),
                'timezone' => TaskIdentity::timezone($event, $timezone),
                'background' => (bool) $event->runInBackground,
                'last_ran_at' => $this->iso($state['ran_at']),
                'last_duration_ms' => $state['duration_ms'],
                'last_exit_code' => $state['exit_code'],
                'last_failed_at' => $this->iso($state['failed_at']),
                'last_exception_class' => $state['exception_class'],
                'last_skipped_at' => $this->iso($state['skipped_at']),
                'last_overlapped_at' => $this->iso($state['overlapped_at']),
                'missed' => $missed,
                'missed_capped' => $capped,
            ];

            $status = $this->worst($status, $this->taskStatus($state, $missed));
        }

        $section = Section::make($this->key(), $status, [
            'observed_since' => $history['since']->toIso8601ZuluString(),
            'tasks' => $tasks,
        ], $now);

        if ($history['reason'] !== null) {
            $section = $section->degraded($history['reason']);
        }

        if ($unnamedCollision) {
            $section = $section->degraded(self::REASON_UNNAMED_CLOSURES);
        }

        return $section;
    }

    /**
     * @param  list<string>  $ids
     * @return array{since: CarbonImmutable, states: array<string, array{ran_at: int|null, duration_ms: int|null, exit_code: int|null, failed_at: int|null, exception_class: string|null, skipped_at: int|null, overlapped_at: int|null}>, blind: bool, reason: string|null}
     */
    private function history(CarbonImmutable $now, array $ids, bool $persist): array
    {
        $states = [];

        try {
            $observed = $this->history->observedSince($now, $persist);

            foreach ($ids as $id) {
                $states[$id] = $this->history->read($id);
            }

            return [
                'since' => $observed['at'],
                'states' => $states,
                // No observation window yet: `missed` would be fabricated, not measured (T11).
                'blind' => $observed['fresh'],
                'reason' => $this->history->usesArrayStore() ? self::REASON_ARRAY_DRIVER : null,
            ];
        } catch (Throwable $exception) {
            // A cache the client cannot reach degrades the section; it never
            // costs them the other collectors.
            Log::warning('Constellation could not read its scheduled task history.', [
                'exception' => $exception->getMessage(),
            ]);

            foreach ($ids as $id) {
                $states[$id] = TaskHistory::EMPTY;
            }

            return ['since' => $now, 'states' => $states, 'blind' => true, 'reason' => self::REASON_CACHE_UNAVAILABLE];
        }
    }

    /**
     * Counts the due dates in ]since, limit] from the cron expression itself:
     * Laravel emits no "missed" signal of any kind (T8).
     *
     * @return array{0: int|null, 1: bool}
     */
    private function missed(Event $event, CarbonImmutable $since, CarbonImmutable $limit, int $cap): array
    {
        if ($cap < 1 || $limit->lessThanOrEqualTo($since)) {
            return [0, false];
        }

        $count = 0;

        for ($nth = 0; $nth < $cap; $nth++) {
            try {
                $due = $event->nextRunDate($since, $nth);
            } catch (Throwable) {
                return [null, false];
            }

            if ($due->getTimestamp() > $limit->getTimestamp()) {
                break;
            }

            $count++;
        }

        return [$count, $count >= $cap];
    }

    /**
     * @param  array{ran_at: int|null, exit_code: int|null, overlapped_at: int|null, ...}  $state
     */
    private function taskStatus(array $state, ?int $missed): Status
    {
        if ($missed !== null && $missed >= (int) $this->config->get('constellation.schedule_tasks.critical_missed', 3)) {
            return Status::Critical;
        }

        if ($missed !== null && $missed >= (int) $this->config->get('constellation.schedule_tasks.warning_missed', 1)) {
            return Status::Warning;
        }

        if ($state['exit_code'] !== null && $state['exit_code'] !== 0) {
            return Status::Warning;
        }

        if ($state['overlapped_at'] !== null && $state['overlapped_at'] > ($state['ran_at'] ?? 0)) {
            return Status::Warning;
        }

        return Status::Ok;
    }

    private function worst(Status $current, Status $candidate): Status
    {
        $rank = [Status::Ok->value => 0, Status::Warning->value => 1, Status::Critical->value => 2];

        return $rank[$candidate->value] > $rank[$current->value] ? $candidate : $current;
    }

    private function iso(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : CarbonImmutable::createFromTimestamp($timestamp, 'UTC')->toIso8601ZuluString();
    }
}
