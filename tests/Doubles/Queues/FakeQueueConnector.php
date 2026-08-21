<?php

namespace Ultraviolettes\Constellation\Tests\Doubles\Queues;

final class FakeQueueConnector
{
    public function __construct(private readonly FakeQueue $queue) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): FakeQueue
    {
        return $this->queue;
    }
}
