<?php

namespace Ultraviolettes\Constellation\Events;

/**
 * A definitive rejection: the payload will never be accepted as is, so the job
 * ends without a second attempt and the cursor stays where it is.
 */
final class SnapshotRejected
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly int $status,
        public readonly ?string $error,
    ) {}
}
