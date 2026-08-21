<?php

namespace Ultraviolettes\Constellation\Collectors\Jobs;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Ultraviolettes\Constellation\Snapshot\Window;
use Ultraviolettes\Constellation\Support\ExceptionParser;
use Ultraviolettes\Constellation\Support\FailureSignature;

/**
 * Mode B — `failed_jobs` only. Structurally degraded: without `queue_monitors`,
 * `processed` has no source in a standard Laravel application.
 */
final class FailedJobsSource
{
    public const NAME = 'failed_jobs';

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

        $failed = $connection->table('failed_jobs')
            ->where('failed_at', '>', $from)
            ->where('failed_at', '<=', $to)
            ->count();

        $rows = $connection->table('failed_jobs')
            ->where('failed_at', '>', $from)
            ->where('failed_at', '<=', $to)
            ->orderBy('failed_at')
            ->limit($maxRows + 1)
            ->get(['queue', 'payload', 'exception', 'failed_at']);

        $truncated = $rows->count() > $maxRows;
        $rows = $rows->take($maxRows);

        $grouper = new FailureGrouper($timezone);

        foreach ($rows as $row) {
            $parsed = ExceptionParser::parse($row->exception === null ? null : (string) $row->exception);
            $exceptionClass = $parsed['class'] ?? '';
            $jobClass = ExceptionParser::jobClassFromPayload($row->payload === null ? null : (string) $row->payload);
            $message = FailureSignature::normalizeMessage($parsed['message'] ?? '');

            $grouper->add(
                FailureSignature::makeSignature($exceptionClass, $jobClass, $message),
                $exceptionClass,
                $jobClass,
                $row->queue === null ? null : (string) $row->queue,
                $message,
                $row->failed_at === null ? null : (string) $row->failed_at,
            );
        }

        return [
            'processed' => null,
            'failed' => $failed,
            'groups' => $grouper->top((int) $this->config->get('constellation.jobs.top_groups', 10)),
            'truncated' => $truncated,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->db
            ->connection($this->config->get('constellation.jobs.connection'))
            ->getSchemaBuilder()
            ->hasTable('failed_jobs');
    }
}
