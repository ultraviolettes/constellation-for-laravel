<?php

namespace Ultraviolettes\Constellation\Tests\Doubles\Backups;

use Spatie\Backup\BackupDestination\BackupDestination;

/**
 * The v8 shape of a status: `failureMessages()` reached the package only in v9,
 * and the collector must report `failures: null` rather than guess (ADR 004 §4).
 */
final class StatusWithoutFailureMessages
{
    public function __construct(
        private readonly BackupDestination $destination,
        private readonly bool $healthy = true,
    ) {}

    public function backupDestination(): BackupDestination
    {
        return $this->destination;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }
}
