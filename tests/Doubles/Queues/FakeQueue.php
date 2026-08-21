<?php

namespace Ultraviolettes\Constellation\Tests\Doubles\Queues;

use RuntimeException;

/**
 * A queue connection as Laravel 11 and 12 < 12.20 hand it over: `size()` and
 * nothing else. `creationTimeOfOldestPendingJob()` is deliberately absent, which
 * is the whole point of the `method_exists()` probe of ADR 004 T2.
 */
class FakeQueue
{
    public ?string $connectionName = null;

    public bool $explodes = false;

    /**
     * @param  array<string, int>  $sizes
     */
    public function __construct(public array $sizes = []) {}

    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    public function setContainer(mixed $container): void {}

    public function size(?string $queue = null): int
    {
        if ($this->explodes) {
            throw new RuntimeException('the queue backend is unreachable');
        }

        return $this->sizes[(string) $queue] ?? 0;
    }
}
