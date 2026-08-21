<?php

namespace Ultraviolettes\Constellation\Collectors\Jobs;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Ultraviolettes\Constellation\Snapshot\Window;
use Ultraviolettes\Constellation\Support\FailureSignature;

/**
 * Mode A — the tables of croustibat/filament-jobs-monitor are present.
 *
 * Reads the plugin's *data* only. `failure_signature` is never read: the SDK
 * always recomputes, so that a client installing or removing the plugin never
 * splits their failure history in two (S22).
 */
final class QueueMonitorSource
{
    public const NAME = 'queue_monitors';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    /**
     * @return array{processed: int|null, failed: int, groups: list<array<string, mixed>>, truncated: bool}
     */
    public function read(Window $window): array
    {
        $connection = $this->db->connection($this->config->get('constellation.jobs.connection'));
        $timezone = (string) $this->config->get('app.timezone', 'UTC');
        $from = $window->from->setTimezone($timezone)->format('Y-m-d H:i:s');
        $to = $window->to->setTimezone($timezone)->format('Y-m-d H:i:s');
        $maxRows = (int) $this->config->get('constellation.jobs.max_rows', 1000);

        $processed = $connection->table('queue_monitors')
            ->where('finished_at', '>', $from)
            ->where('finished_at', '<=', $to)
            ->count();

        $failed = $connection->table('queue_monitors')
            ->where('finished_at', '>', $from)
            ->where('finished_at', '<=', $to)
            ->where('failed', true)
            ->count();

        $rows = $connection->table('queue_monitors')
            ->where('finished_at', '>', $from)
            ->where('finished_at', '<=', $to)
            ->where('failed', true)
            ->orderBy('finished_at')
            ->limit($maxRows + 1)
            ->get(['name', 'queue', 'finished_at', 'exception_class', 'exception_message']);

        $truncated = $rows->count() > $maxRows;
        $rows = $rows->take($maxRows);

        $grouper = new FailureGrouper($timezone);

        foreach ($rows as $row) {
            $exceptionClass = (string) $row->exception_class;
            $jobClass = $row->name === null ? null : (string) $row->name;
            $message = FailureSignature::normalizeMessage((string) $row->exception_message);

            $grouper->add(
                FailureSignature::makeSignature($exceptionClass, $jobClass, $message),
                $exceptionClass,
                $jobClass,
                $row->queue === null ? null : (string) $row->queue,
                $message,
                $row->finished_at === null ? null : (string) $row->finished_at,
            );
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'groups' => $grouper->top((int) $this->config->get('constellation.jobs.top_groups', 10)),
            'truncated' => $truncated,
        ];
    }

    public function isAvailable(): bool
    {
        $schema = $this->db->connection($this->config->get('constellation.jobs.connection'))->getSchemaBuilder();

        return $schema->hasTable('queue_monitors') && $schema->hasTable('queue_monitor_failure_groups');
    }
}
