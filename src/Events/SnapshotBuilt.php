<?php

namespace Ultraviolettes\Constellation\Events;

use Ultraviolettes\Constellation\Snapshot\Snapshot;

final class SnapshotBuilt
{
    public function __construct(public readonly Snapshot $snapshot) {}
}
