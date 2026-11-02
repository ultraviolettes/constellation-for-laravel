<?php

use Carbon\CarbonImmutable;
use Ultraviolettes\Constellation\Collectors\BackupsCollector;
use Ultraviolettes\Constellation\Constellation;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Snapshot;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

/**
 * The J3 payload, frozen whole (§6 of the payload document).
 *
 * Every section is built by the production `Section` class and wrapped by the
 * production `Snapshot`, in the five states the ingest endpoint has to accept:
 * `ok`, `warning`, `degraded` and `unavailable`. Any renamed key, any field that
 * disappears, any `sent_at` that moves fails this test loudly — which is the
 * whole point. It is meant to be edited only alongside the ingest contract.
 *
 * Amended once, deliberately: the three dead-worker fields of each queue row
 * (`last_queued_at`, `last_started_at`, `stalled`) were appended after the J3
 * freeze. Additive only — no key moved, none disappeared — and accepted as is
 * by the ingest endpoint, which stores `data` verbatim (rule 3 of §2).
 *
 * The sections are stated here rather than collected, on purpose: a frozen
 * vector must not move with the PHP version, the Laravel version or the clock of
 * the machine running the suite. What the *collectors* produce is frozen in
 * their own tests, key by key.
 */
const FROZEN_SNAPSHOT = __DIR__.'/../Fixtures/frozen_snapshot.json';

const FROZEN_AT = '2026-08-21T14:00:00Z';

function frozenWindow(): Window
{
    $to = CarbonImmutable::parse(FROZEN_AT);

    return new Window($to->subMinutes(15), $to, Window::SOURCE_CURSOR);
}

function frozenSnapshot(): Snapshot
{
    $window = frozenWindow();
    $at = $window->to;

    return new Snapshot([
        'schema' => Constellation::SCHEMA,
        'sent_at' => $at->toIso8601ZuluString(),
        'window' => $window->toArray(),
        'app' => [
            'name' => 'Acme',
            'environment' => 'production',
            'url' => 'https://acme.example.com',
            'php_version' => '8.3.14',
            'laravel_version' => '12.62.0',
            'debug_mode' => false,
            'server_time' => $at->toIso8601ZuluString(),
        ],
        'sections' => [
            'app' => Section::make('app', Status::Ok, [
                'name' => 'Acme',
                'environment' => 'production',
                'url' => 'https://acme.example.com',
                'php_version' => '8.3.14',
                'laravel_version' => '12.62.0',
                'debug_mode' => false,
                'server_time' => $at->toIso8601ZuluString(),
                'pending_migrations' => 0,
                'config_cached' => true,
                'routes_cached' => true,
            ], $at)->toArray(),

            'jobs' => Section::make('jobs', Status::Warning, [
                'window_source' => Window::SOURCE_CURSOR,
                'source' => 'queue_monitors',
                'totals' => [
                    'processed' => 1284,
                    'failed' => 23,
                    'success_rate' => 0.98209,
                ],
                'failure_groups' => [
                    [
                        'signature' => 'f88a4427bfec617dec0d7b1585fbaa683fea8bc54a8ad56344ae6ad55fd1f873',
                        'exception_class' => 'Illuminate\Database\QueryException',
                        'job_class' => 'App\Jobs\SyncInvoices',
                        'queue' => 'default',
                        'message' => "SQLSTATE[HY000] [<num>] Connection refused to '<str>'",
                        'occurrences' => 23,
                        'first_occurred_at' => '2026-08-21T13:47:11Z',
                        'last_occurred_at' => '2026-08-21T13:58:02Z',
                    ],
                ],
            ], $at)->toArray(),

            'queues' => Section::make('queues', Status::Warning, [
                'throughput_source' => 'queue_monitors',
                'queues' => [
                    [
                        'connection' => 'database',
                        'name' => 'default',
                        'driver' => 'database',
                        'pending' => 12,
                        'oldest_pending_age_seconds' => 41,
                        'throughput_window' => 1284,
                        'failed_window' => 2,
                        'last_queued_at' => '2026-08-21T13:59:31Z',
                        'last_started_at' => '2026-08-21T13:59:20Z',
                        'stalled' => false,
                    ],
                    [
                        'connection' => 'redis',
                        'name' => 'high',
                        'driver' => 'redis',
                        'pending' => 402,
                        'oldest_pending_age_seconds' => null,
                        'throughput_window' => 96,
                        'failed_window' => 0,
                        'last_queued_at' => '2026-08-21T13:58:12Z',
                        'last_started_at' => '2026-08-21T13:57:40Z',
                        'stalled' => false,
                    ],
                ],
            ], $at)->degraded('queue_driver_unsupported:redis')->toArray(),

            // No observation at all: the only form whose `collected_at` is null.
            'backups' => Section::unavailable('backups', BackupsCollector::REASON_NOT_INSTALLED)->toArray(),

            'schedule' => Section::make('schedule', Status::Ok, [
                'observed_since' => '2026-08-14T09:12:00Z',
                'tasks' => [
                    [
                        'id' => '3f2a1c9d8e7b6a54',
                        'command' => 'php artisan invoices:sync',
                        'expression' => '0 * * * *',
                        'timezone' => 'Europe/Paris',
                        'background' => false,
                        'last_ran_at' => '2026-08-21T13:00:04Z',
                        'last_duration_ms' => 1840,
                        'last_exit_code' => 0,
                        'last_failed_at' => null,
                        'last_exception_class' => null,
                        'last_skipped_at' => null,
                        'last_overlapped_at' => null,
                        'missed' => 0,
                        'missed_capped' => false,
                    ],
                ],
            ], $at)->toArray(),
        ],
    ], $window);
}

function frozenJson(): string
{
    return rtrim((string) file_get_contents(FROZEN_SNAPSHOT), "\n");
}

it('freezes the whole payload, byte for byte', function () {
    // If this fails, the wire format changed. Read the diff before touching the
    // fixture: the ingest endpoint validates against exactly this shape.
    expect(frozenSnapshot()->toPrettyJson())->toBe(frozenJson());
});

it('carries the schema number the ingest endpoint dispatches on', function () {
    $payload = json_decode(frozenJson(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['schema'])->toBe(1)
        ->and($payload['schema'])->toBe(Constellation::SCHEMA)
        ->and(array_keys($payload))->toBe(['schema', 'sent_at', 'window', 'app', 'sections']);
});

it('stamps sent_at at the root, once, and never inside a section', function () {
    $payload = json_decode(frozenJson(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['sent_at'])->toBe(FROZEN_AT)
        ->and($payload['window'])->toBe(['from' => '2026-08-21T13:45:00Z', 'to' => FROZEN_AT]);

    foreach ($payload['sections'] as $key => $section) {
        expect($section)->not->toHaveKey('sent_at', "section [{$key}]");
    }
});

it('gives every section the four envelope fields, in order, even when reason is null', function () {
    $payload = json_decode(frozenJson(), true, 512, JSON_THROW_ON_ERROR);

    foreach ($payload['sections'] as $key => $section) {
        expect(array_keys($section))->toBe(['status', 'quality', 'reason', 'collected_at', 'data'], "section [{$key}]")
            // S12: an absent key and a null value are not the same thing on the
            // wire. `reason` is always there, and null when there is none.
            ->and(array_key_exists('reason', $section))->toBeTrue()
            ->and(array_key_exists('collected_at', $section))->toBeTrue();
    }

    expect($payload['sections']['app']['reason'])->toBeNull()
        ->and($payload['sections']['schedule']['reason'])->toBeNull()
        ->and(frozenJson())->toContain('"reason": null');
});

it('never lets an unavailable section claim a complete observation', function () {
    $backups = json_decode(frozenJson(), true, 512, JSON_THROW_ON_ERROR)['sections']['backups'];

    // Nothing was read: `quality: full` would read on the wire as "I looked and
    // everything was there" (ADR 004 R6).
    expect($backups)->toBe([
        'status' => 'unavailable',
        'quality' => 'degraded',
        'reason' => 'collector_not_installed',
        'collected_at' => null,
        'data' => [],
    ])
        ->and(frozenJson())->toContain('"data": {}');
});

it('covers the four forms a section can take', function () {
    $sections = json_decode(frozenJson(), true, 512, JSON_THROW_ON_ERROR)['sections'];

    expect(array_column($sections, 'status'))->toBe(['ok', 'warning', 'warning', 'unavailable', 'ok'])
        ->and(array_column($sections, 'quality'))->toBe(['full', 'full', 'degraded', 'degraded', 'full'])
        ->and($sections['queues']['reason'])->toBe('queue_driver_unsupported:redis');
});

it('freezes the same envelope the builder assembles from live collectors', function () {
    useCursorCache();
    config()->set('constellation.token', 'frozen-token');

    $built = json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR);
    $frozen = json_decode(frozenJson(), true, 512, JSON_THROW_ON_ERROR);

    // The fixture is stated by hand so it never moves with the machine; this is
    // what ties it back to what the SDK actually assembles.
    expect(array_keys($built))->toBe(array_keys($frozen))
        ->and(array_keys($built['window']))->toBe(array_keys($frozen['window']))
        ->and(array_keys($built['app']))->toBe(array_keys($frozen['app']))
        ->and(array_keys($built['sections']))->toBe(array_keys($frozen['sections']))
        ->and(array_keys($built['sections']['app']['data']))->toBe(array_keys($frozen['sections']['app']['data']))
        ->and(array_keys($built['sections']['queues']['data']))->toBe(array_keys($frozen['sections']['queues']['data']))
        ->and(array_keys($built['sections']['schedule']['data']))->toBe(array_keys($frozen['sections']['schedule']['data']));

    foreach ($built['sections'] as $key => $section) {
        expect(array_keys($section))->toBe(array_keys($frozen['sections'][$key]), "section [{$key}]");
    }
});

it('sends a payload small enough to travel', function () {
    expect(strlen(frozenSnapshot()->body))->toBeLessThan(16384);
});
