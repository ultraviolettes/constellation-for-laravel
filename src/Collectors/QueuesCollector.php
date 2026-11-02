<?php

namespace Ultraviolettes\Constellation\Collectors;

use Illuminate\Contracts\Config\Repository as Config;
use Ultraviolettes\Constellation\Collectors\Jobs\QueueMonitorSource;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Collectors\Queues\QueueDepth;
use Ultraviolettes\Constellation\Collectors\Queues\QueueInventory;
use Ultraviolettes\Constellation\Collectors\Queues\QueueThroughput;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * Depth, head age, throughput and failures per queue.
 *
 * The status hangs on the age of the oldest pending job (ADR 004 T18): a deep
 * queue that moves is a queue that works, while a queue whose head ages is a
 * dead worker. Where that age cannot be read — Redis and SQS before 12.20 (T16)
 * — `stalled` says the same thing from two cached timestamps: jobs were queued,
 * work is still waiting, and no worker started one for `stalled_after_seconds`.
 *
 * The stall signal never degrades the section. It is a second witness, not the
 * observation the `quality` axis vouches for: an array cache or a worker never
 * seen starting makes it `null`, and `oldest_pending_age_seconds` keeps its own
 * reserve (`queue_driver_unsupported:<driver>`) exactly as before.
 */
final class QueuesCollector implements Collector
{
    public const REASON_MONITOR_ABSENT = 'queue_monitor_absent';

    public const REASON_DRIVER_UNSUPPORTED = 'queue_driver_unsupported:';

    public const REASON_READ_FAILED = 'queue_read_failed:';

    public const REASON_AMBIGUOUS = 'throughput_connection_ambiguous';

    public const REASON_TRUNCATED = 'queue_list_truncated';

    public function __construct(
        private readonly QueueInventory $inventory,
        private readonly QueueDepth $depth,
        private readonly QueueThroughput $throughput,
        private readonly QueueActivity $activity,
        private readonly Config $config,
    ) {}

    public function key(): string
    {
        return 'queues';
    }

    public function collect(Window $window): Section
    {
        $now = $window->to;
        $queues = $this->inventory->all($now);

        // An application running everything on `sync` has no queue to watch. That
        // is a complete observation whose content is empty, not a degradation (T19).
        if ($queues === []) {
            return Section::make($this->key(), Status::Ok, ['throughput_source' => null, 'queues' => []], $now);
        }

        $throughput = $this->throughput->read($window, $queues);
        $claimed = $this->inventory->claimed();

        // Depth first: the stall verdict needs it — a readable, empty queue is
        // never stalled, whatever the timestamps say.
        $depths = [];

        foreach ($queues as $index => $queue) {
            $depths[$index] = $this->depth->read($queue, $now);
        }

        $activity = $this->activity->assess(
            $queues,
            $now,
            array_map(fn (array $depth): ?int => $depth['unreadable'] ? null : $depth['pending'], $depths),
        );

        $rows = [];
        $unsupported = [];
        $unreadable = [];
        $status = Status::Ok;

        foreach ($queues as $index => $queue) {
            $depth = $depths[$index];

            if ($depth['unreadable']) {
                // Only a connection the application claims is worth reporting as
                // unreadable; the rest is stock configuration for drivers it does
                // not use, and would degrade every snapshot forever.
                if (in_array($queue['connection'], $claimed, true)) {
                    $unreadable[$queue['connection']] = true;
                }

                continue;
            }

            if ($depth['unsupported']) {
                $unsupported[$queue['driver']] = true;
            }

            $rows[] = [
                'connection' => $queue['connection'],
                'name' => $queue['name'],
                'driver' => $queue['driver'],
                // `size()` counts delayed and reserved jobs too: queue depth, not
                // strictly pending work (R8). The status never rests on it.
                'pending' => $depth['pending'],
                'oldest_pending_age_seconds' => $depth['age'],
                'throughput_window' => $throughput['rows'][$index]['throughput'],
                'failed_window' => $throughput['rows'][$index]['failed'],
                // Appended, never inserted: an addition must read as one in the
                // frozen vector. The server stores `data` as jsonb, unknown
                // fields included (rule 3 of §2) — order is not kept there.
                'last_queued_at' => $activity[$index]['last_queued_at'],
                'last_started_at' => $activity[$index]['last_started_at'],
                'stalled' => $activity[$index]['stalled'],
            ];

            $status = $this->worst($status, $this->queueStatus($depth['age']));

            // No start for a whole threshold while work waits: the dead worker
            // of §3, as critical as a head older than `critical_age_seconds`.
            // One snapshot says it; the server's two-consecutive rule decides
            // whether it becomes an incident (ADR 004 T18, as amended).
            if ($activity[$index]['stalled'] === true) {
                $status = $this->worst($status, Status::Critical);
            }
        }

        $max = (int) $this->config->get('constellation.queues.max', 20);
        $truncated = count($rows) > $max;

        if ($truncated) {
            $rows = $this->truncate($rows, $max);
        }

        $section = Section::make($this->key(), $status, [
            'throughput_source' => $throughput['source'],
            'queues' => $rows,
        ], $now);

        // Detection order matters: reasons concatenate with '+' (S13).
        foreach (array_keys($unsupported) as $driver) {
            $section = $section->degraded(self::REASON_DRIVER_UNSUPPORTED.$driver);
        }

        foreach (array_keys($unreadable) as $connection) {
            $section = $section->degraded(self::REASON_READ_FAILED.$connection);
        }

        if ($throughput['source'] !== QueueMonitorSource::NAME) {
            $section = $section->degraded(self::REASON_MONITOR_ABSENT);
        }

        if ($throughput['ambiguous']) {
            $section = $section->degraded(self::REASON_AMBIGUOUS);
        }

        if ($truncated) {
            $section = $section->degraded(self::REASON_TRUNCATED);
        }

        return $section;
    }

    /**
     * A snapshot is a bounded object (§1), but a stalled queue is the one line
     * the section exists for: stalled rows come first and are never cut, even
     * past `queues.max`; the rest fill what is left, deepest first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function truncate(array $rows, int $max): array
    {
        $stalled = array_values(array_filter($rows, fn (array $row): bool => $row['stalled'] === true));
        $others = array_values(array_filter($rows, fn (array $row): bool => $row['stalled'] !== true));

        usort($others, fn (array $a, array $b): int => ($b['pending'] ?? 0) <=> ($a['pending'] ?? 0));

        return [...$stalled, ...array_slice($others, 0, max(0, $max - count($stalled)))];
    }

    private function queueStatus(?int $age): Status
    {
        // A queue whose age could not be read does not contribute: `pending` was
        // read and founds the status for what it is worth, the reserve rides on
        // the `quality` axis (S11).
        if ($age === null) {
            return Status::Ok;
        }

        if ($age >= (int) $this->config->get('constellation.queues.critical_age_seconds', 900)) {
            return Status::Critical;
        }

        if ($age >= (int) $this->config->get('constellation.queues.warning_age_seconds', 300)) {
            return Status::Warning;
        }

        return Status::Ok;
    }

    private function worst(Status $current, Status $candidate): Status
    {
        $rank = [Status::Ok->value => 0, Status::Warning->value => 1, Status::Critical->value => 2];

        return $rank[$candidate->value] > $rank[$current->value] ? $candidate : $current;
    }
}
