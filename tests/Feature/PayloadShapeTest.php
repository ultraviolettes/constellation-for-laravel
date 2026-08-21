<?php

use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;

/**
 * Freezes the shape the ingest endpoint validates against (ADR 002 §4). A field
 * that moves here moves on the wire, so this test is meant to be edited only
 * alongside the ingest contract.
 */
beforeEach(function () {
    config()->set('constellation.token', 'shape-token');
});

function payload(): array
{
    return json_decode(app(SnapshotBuilder::class)->build()->body, true, 512, JSON_THROW_ON_ERROR);
}

it('carries exactly the five root keys', function () {
    expect(array_keys(payload()))->toBe(['schema', 'sent_at', 'window', 'app', 'sections']);
});

it('stamps every timestamp in zulu UTC, whatever the timezone of the app', function () {
    config()->set('app.timezone', 'Pacific/Auckland');

    $payload = payload();

    $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    expect($payload['sent_at'])->toMatch($iso)
        ->and($payload['window']['from'])->toMatch($iso)
        ->and($payload['window']['to'])->toMatch($iso)
        ->and($payload['app']['server_time'])->toMatch($iso)
        ->and($payload['sections']['app']['collected_at'])->toMatch($iso);
});

it('gives every section the two axes, a reason and a collection time', function () {
    foreach (payload()['sections'] as $key => $section) {
        expect(array_keys($section))->toBe(['status', 'quality', 'reason', 'collected_at', 'data'])
            ->and($section['status'])->toBeIn(['ok', 'warning', 'critical', 'unavailable'])
            ->and($section['quality'])->toBeIn(['full', 'degraded'])
            ->and($key)->toMatch('/^[a-z][a-z0-9_]{0,39}$/');
    }
});

it('stamps every section with the one clock read of the snapshot', function () {
    useCursorCache();
    createFailedJobsTable();

    $payload = payload();

    foreach ($payload['sections'] as $key => $section) {
        // A section that observed nothing has no collection time to stamp (S10);
        // every other one carries the single clock read of the snapshot.
        expect($section['collected_at'])->toBe(
            $section['status'] === 'unavailable' ? null : $payload['sent_at'],
            "section [{$key}]"
        );
    }
});

it('sends the five sections of the J3 scope, the schedule one last', function () {
    expect(array_keys(payload()['sections']))->toBe(['app', 'jobs', 'queues', 'backups', 'schedule']);
});

it('freezes the shape of a failure group', function () {
    useCursorCache();
    createFailedJobsTable();
    insertFailedJob();

    $group = payload()['sections']['jobs']['data']['failure_groups'][0];

    expect(array_keys($group))->toBe([
        'signature',
        'exception_class',
        'job_class',
        'queue',
        'message',
        'occurrences',
        'first_occurred_at',
        'last_occurred_at',
    ])
        ->and($group['signature'])->toMatch('/^[0-9a-f]{64}$/');
});

it('freezes the shape of the jobs data block', function () {
    useCursorCache();
    createFailedJobsTable();

    expect(array_keys(payload()['sections']['jobs']['data']))
        ->toBe(['window_source', 'source', 'totals', 'failure_groups']);
});

it('generates a v4 idempotency key per snapshot', function () {
    expect(app(SnapshotBuilder::class)->build()->idempotencyKey)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});
