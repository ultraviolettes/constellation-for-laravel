<?php

namespace Ultraviolettes\Constellation\Events;

final class SnapshotSent
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $windowTo,
        public readonly bool $replayed = false,
    ) {}
}
