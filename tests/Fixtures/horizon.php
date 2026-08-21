<?php

namespace Laravel\Horizon;

/**
 * Horizon is probed by name and never called: `QueueInventory` reads
 * `config('horizon.environments')` and nothing else (ADR 004 T13).
 */
if (! class_exists(Horizon::class, false)) {
    class Horizon {}
}
