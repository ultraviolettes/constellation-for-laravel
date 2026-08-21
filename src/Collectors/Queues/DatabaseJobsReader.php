<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reads the core Laravel `jobs` table — a framework migration, never something
 * the SDK adds (ADR 003 S6). The query is ported verbatim from
 * `DatabaseQueue::creationTimeOfOldestPendingJob()` and answers three questions
 * at once: which queues exist, how deep they are, and how old their head is.
 *
 * Used only when the connection cannot answer for itself (ADR 004 T15).
 */
final class DatabaseJobsReader
{
    /** @var array<string, array<string, array{total: int, oldest: int|null}>> */
    private array $memo = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    /**
     * @return array<string, array{total: int, oldest: int|null}> Keyed by queue name.
     */
    public function rows(string $connection, CarbonImmutable $now): array
    {
        if (isset($this->memo[$connection])) {
            return $this->memo[$connection];
        }

        $table = $this->config->get('queue.connections.'.$connection.'.table');
        $table = is_string($table) && $table !== '' ? $table : 'jobs';
        $database = $this->config->get('queue.connections.'.$connection.'.connection');

        try {
            $db = $this->db->connection(is_string($database) ? $database : null);

            if (! $db->getSchemaBuilder()->hasTable($table)) {
                return $this->memo[$connection] = [];
            }

            // `reserved_at IS NULL` drops what a worker already took; the bound on
            // `available_at` drops delayed jobs — a job due tomorrow is not late.
            $rows = $db->table($table)
                ->selectRaw('queue, COUNT(*) as total, MIN(available_at) as oldest_available_at')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now->getTimestamp())
                ->groupBy('queue')
                ->get();
        } catch (Throwable) {
            return $this->memo[$connection] = [];
        }

        $parsed = [];

        foreach ($rows as $row) {
            $parsed[(string) $row->queue] = [
                'total' => (int) $row->total,
                'oldest' => is_numeric($row->oldest_available_at) ? (int) $row->oldest_available_at : null,
            ];
        }

        return $this->memo[$connection] = $parsed;
    }
}
