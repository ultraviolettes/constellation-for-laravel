<?php

use Ultraviolettes\Constellation\Collectors\AppCollector;
use Ultraviolettes\Constellation\Collectors\JobsCollector;

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
    ],

    'jobs' => [
        'connection' => null,
        'top_groups' => 10,
        'max_rows' => 1000,
        'warning_failures' => 1,
        'critical_failures' => 25,
    ],

    'app' => [
        'send_url' => true,
    ],

];
