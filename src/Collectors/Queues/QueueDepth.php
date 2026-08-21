<?php

namespace Ultraviolettes\Constellation\Collectors\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * Depth and head age of one queue.
 *
 * `size()` has always been on the contract; `creationTimeOfOldestPendingJob()`
 * only reached it in Laravel 13, so it is probed rather than type-hinted
 * (ADR 004 T2, T14) — exactly what `queue:monitor` does.
 */
final class QueueDepth
{
    /** Drivers that never expose the age of their head, on any supported version. */
    public const NO_AGE_DRIVERS = ['sqs', 'beanstalkd'];

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly DatabaseJobsReader $jobs,
    ) {}

    /**
     * @param  array{connection: string, name: string, driver: string}  $queue
     * @return array{pending: int|null, age: int|null, unsupported: bool, unreadable: bool}
     */
    public function read(array $queue, CarbonImmutable $now): array
    {
        try {
            $connection = $this->queues->connection($queue['connection']);
            $pending = (int) $connection->size($queue['name']);
        } catch (Throwable) {
            return ['pending' => null, 'age' => null, 'unsupported' => false, 'unreadable' => true];
        }

        // Verified in vendor: SQS and Beanstalkd carry the method on Laravel 13
        // and return a hardcoded null ("Not supported by ..."). Probing alone
        // would let a null field pass as a complete observation.
        if (in_array($queue['driver'], self::NO_AGE_DRIVERS, true)) {
            return ['pending' => $pending, 'age' => null, 'unsupported' => true, 'unreadable' => false];
        }

        $age = $this->oldestPendingAge($connection, $queue['name'], $now);

        if ($age !== false) {
            return ['pending' => $pending, 'age' => $age, 'unsupported' => false, 'unreadable' => false];
        }

        if ($queue['driver'] === 'database') {
            $oldest = $this->jobs->rows($queue['connection'], $now)[$queue['name']]['oldest'] ?? null;

            return [
                'pending' => $pending,
                'age' => $oldest === null ? null : max(0, $now->getTimestamp() - $oldest),
                'unsupported' => false,
                'unreadable' => false,
            ];
        }

        // Redis before 12.20 does not even put `createdAt` in the payload: there
        // is nothing to read. An absent, documented field beats a wrong number (T16).
        return ['pending' => $pending, 'age' => null, 'unsupported' => true, 'unreadable' => false];
    }

    /**
     * Deliberately takes an opaque object: the method is optional across the
     * Laravel versions this package supports.
     *
     * @return int|null|false `false` when the connection cannot answer at all.
     */
    private function oldestPendingAge(object $connection, string $queue, CarbonImmutable $now): int|false|null
    {
        if (! method_exists($connection, 'creationTimeOfOldestPendingJob')) {
            return false;
        }

        try {
            $created = $connection->creationTimeOfOldestPendingJob($queue);
        } catch (Throwable) {
            return false;
        }

        return is_numeric($created) ? max(0, $now->getTimestamp() - (int) $created) : null;
    }
}
