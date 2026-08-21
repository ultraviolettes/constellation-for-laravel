<?php

namespace Ultraviolettes\Constellation\Tests\Doubles\Queues;

/**
 * The Laravel 13 shape: the connection answers for the age of its own head.
 */
final class AgingQueue extends FakeQueue
{
    /**
     * @param  array<string, int>  $sizes
     */
    public function __construct(array $sizes = [], public ?int $oldestCreatedAt = null)
    {
        parent::__construct($sizes);
    }

    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->oldestCreatedAt;
    }
}
