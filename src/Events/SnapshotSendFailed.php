<?php

namespace Ultraviolettes\Constellation\Events;

final class SnapshotSendFailed
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $reason,
        public readonly int $attempts,
    ) {}
}
