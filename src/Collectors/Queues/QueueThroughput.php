<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Throwable;
use Ultraviolettes\Constellation\Collectors\Jobs\FailedJobsSource;
use Ultraviolettes\Constellation\Collectors\Jobs\QueueMonitorSource;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * Throughput and failures over the snapshot window, per queue (ADR 004 T17).
 *
 * Mode A reads the plugin's `queue_monitors`; mode B falls back to core
 * `failed_jobs`, which knows failures only — a standard Laravel application has
 * no source at all for the number of jobs it processed.
 */
final class QueueThroughput
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
        private readonly QueueMonitorSource $monitors,
        private readonly FailedJobsSource $failures,
    ) {}

    /**
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @return array{source: string|null, ambiguous: bool, rows: list<array{throughput: int|null, failed: int|null}>}
     */
    public function read(Window $window, array $queues): array
    {
        if ($this->monitors->isAvailable()) {
            return $this->fromQueueMonitors($window, $queues);
        }

        if ($this->failures->isAvailable()) {
            return $this->fromFailedJobs($window, $queues);
        }

        return [
            'source' => null,
            'ambiguous' => false,
            'rows' => array_map(fn (): array => ['throughput' => null, 'failed' => null], $queues),
        ];
    }

    /**
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @return array{source: string|null, ambiguous: bool, rows: list<array{throughput: int|null, failed: int|null}>}
     */
    private function fromQueueMonitors(Window $window, array $queues): array
    {
        [$from, $to] = $this->bounds($window);

        try {
            $rows = $this->connection()
                ->table('queue_monitors')
                ->selectRaw('queue, failed, COUNT(*) as total')
                ->where('finished_at', '>', $from)
                ->where('finished_at', '<=', $to)
                ->groupBy('queue', 'failed')
                ->get();
        } catch (Throwable) {
            return $this->fromFailedJobs($window, $queues);
        }

        $throughput = [];
        $failed = [];

        foreach ($rows as $row) {
            $queue = (string) $row->queue;

            if ($row->failed) {
                $failed[$queue] = ($failed[$queue] ?? 0) + (int) $row->total;
            } else {
                $throughput[$queue] = ($throughput[$queue] ?? 0) + (int) $row->total;
            }
        }

        return [
            'source' => QueueMonitorSource::NAME,
            // `queue_monitors` has no `connection` column: two watched connections
            // sharing a queue name cannot be told apart in mode A.
            'ambiguous' => $this->sharesQueueNames($queues),
            'rows' => array_map(fn (array $queue): array => [
                'throughput' => $throughput[$queue['name']] ?? 0,
                'failed' => $failed[$queue['name']] ?? 0,
            ], $queues),
        ];
    }

    /**
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     * @return array{source: string|null, ambiguous: bool, rows: list<array{throughput: int|null, failed: int|null}>}
     */
    private function fromFailedJobs(Window $window, array $queues): array
    {
        [$from, $to] = $this->bounds($window);

        try {
            $rows = $this->connection()
                ->table('failed_jobs')
                ->selectRaw('connection, queue, COUNT(*) as total')
                ->where('failed_at', '>', $from)
                ->where('failed_at', '<=', $to)
                ->groupBy('connection', 'queue')
                ->get();
        } catch (Throwable) {
            return [
                'source' => null,
                'ambiguous' => false,
                'rows' => array_map(fn (): array => ['throughput' => null, 'failed' => null], $queues),
            ];
        }

        $failed = [];

        foreach ($rows as $row) {
            $key = (string) $row->connection."\0".(string) $row->queue;
            $failed[$key] = ($failed[$key] ?? 0) + (int) $row->total;
        }

        return [
            'source' => FailedJobsSource::NAME,
            'ambiguous' => false,
            'rows' => array_map(fn (array $queue): array => [
                'throughput' => null,
                'failed' => $failed[$queue['connection']."\0".$queue['name']] ?? 0,
            ], $queues),
        ];
    }

    /**
     * @param  list<array{connection: string, name: string, driver: string}>  $queues
     */
    private function sharesQueueNames(array $queues): bool
    {
        $connections = [];

        foreach ($queues as $queue) {
            $connections[$queue['name']][$queue['connection']] = true;

            if (count($connections[$queue['name']]) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function bounds(Window $window): array
    {
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        return [
            $window->from->setTimezone($timezone)->format('Y-m-d H:i:s'),
            $window->to->setTimezone($timezone)->format('Y-m-d H:i:s'),
        ];
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->config->get('constellation.jobs.connection'));
    }
}
