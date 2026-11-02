<?php

use Ultraviolettes\Constellation\Collectors\AppCollector;
use Ultraviolettes\Constellation\Collectors\BackupsCollector;
use Ultraviolettes\Constellation\Collectors\JobsCollector;
use Ultraviolettes\Constellation\Collectors\QueuesCollector;
use Ultraviolettes\Constellation\Collectors\ScheduleCollector;

return [

    'enabled' => env('CONSTELLATION_ENABLED', true),

    'endpoint' => env('CONSTELLATION_ENDPOINT', 'https://app.constellation.dev/api/v1/snapshots'),

    'token' => env('CONSTELLATION_TOKEN'),

    'schedule' => [
        'enabled' => env('CONSTELLATION_SCHEDULE', true),
        'cron_expression' => env('CONSTELLATION_CRON', '*/15 * * * *'),
    ],

    'queue' => [
        'connection' => env('CONSTELLATION_QUEUE_CONNECTION'),
        'queue' => env('CONSTELLATION_QUEUE'),
    ],

    'http' => [
        'timeout' => 10,
        'connect_timeout' => 5,
    ],

    'window' => [
        'fallback_minutes' => 15,
        'max_hours' => 24,
    ],

    'cache' => [
        'store' => env('CONSTELLATION_CACHE_STORE'),
        'prefix' => 'constellation',
        'ttl_days' => 7,
    ],

    'collectors' => [
        AppCollector::class,
        JobsCollector::class,
        QueuesCollector::class,
        BackupsCollector::class,
        // Last on purpose: it is the only collector whose accuracy depends on
        // listeners registered elsewhere, so its absence reads plainly in the
        // JSON of `monitor:send --dry-run`.
        ScheduleCollector::class,
    ],

    'jobs' => [
        'connection' => null,
        'top_groups' => 10,
        'max_rows' => 1000,
        'warning_failures' => 1,
        'critical_failures' => 25,
    ],

    'queues' => [
        // ['redis:high', 'sqs:default'] — the syntax of `queue:monitor`.
        'monitor' => [],
        'max' => 20,
        // Both to calibrate on real data (J5).
        'warning_age_seconds' => 300,
        'critical_age_seconds' => 900,
        // No job started for this long while jobs were queued: the dead-worker
        // signal that also works where the head age cannot be read (Redis/SQS
        // before 12.20). One hour clears the longest legitimate job on a
        // single-worker queue (exports, imports, backups) and leaves room for
        // the confirmation by a second snapshot; see the README.
        'stalled_after_seconds' => 3600,
    ],

    // Not 'schedule': that key already carries the SDK's own scheduling (S31).
    'schedule_tasks' => [
        'grace_seconds' => 90,
        'missed_cap' => 100,
        'warning_missed' => 1,
        'critical_missed' => 3,
        // An exec() line is client-written shell and may carry a secret.
        'send_exec_command' => false,
    ],

    'backups' => [
        // Reserved, not implemented: see R3 of ADR 004.
        'connection_timeout' => null,
    ],

    'app' => [
        'send_url' => true,
    ],

];
