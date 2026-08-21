<?php

use Carbon\CarbonImmutable;
use Ultraviolettes\Constellation\Snapshot\Quality;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;

function sectionAt(Status $status = Status::Ok, array $data = ['a' => 1]): Section
{
    return Section::make('jobs', $status, $data, CarbonImmutable::parse('2026-08-17T14:00:00Z'));
}

it('emits the four axes on every section, reason included when there is nothing to say', function () {
    expect(sectionAt()->toArray())->toBe([
        'status' => 'ok',
        'quality' => 'full',
        'reason' => null,
        'collected_at' => '2026-08-17T14:00:00Z',
        'data' => ['a' => 1],
    ]);
});

it('marks an unavailable section unavailable, with no invented status', function () {
    $section = Section::unavailable('backups', 'collector_not_installed');

    expect($section->status)->toBe(Status::Unavailable)
        ->and($section->quality)->toBe(Quality::Full)
        ->and($section->reason)->toBe('collector_not_installed')
        ->and($section->collectedAt)->toBeNull()
        ->and($section->data)->toBe([]);
});

it('encodes an empty data as an object, never as an array', function () {
    $encoded = json_encode(Section::unavailable('backups', 'collector_not_installed')->toArray());

    expect($encoded)->toContain('"data":{}')->not->toContain('"data":[]');
});

it('concatenates degradation reasons with a plus, in detection order', function () {
    $section = sectionAt()->degraded('cache_driver_array')->degraded('queue_monitor_absent');

    expect($section->quality)->toBe(Quality::Degraded)
        ->and($section->reason)->toBe('cache_driver_array+queue_monitor_absent');
});

it('truncates reason to the width of the ingest column', function () {
    $section = sectionAt()->degraded(str_repeat('z', 260));

    expect($section->reason)->toHaveLength(200);
});

it('truncates an unavailable reason too', function () {
    expect(Section::unavailable('jobs', str_repeat('z', 260))->reason)->toHaveLength(200);
});

it('never promotes nor demotes the status when the quality drops', function (Status $status) {
    expect(sectionAt($status)->degraded('cache_driver_array')->status)->toBe($status);
})->with([
    [Status::Ok],
    [Status::Warning],
    [Status::Critical],
]);

it('keeps the collected facts when the quality drops', function () {
    $section = sectionAt(data: ['failed' => 3])->degraded('window_truncated');

    expect($section->data)->toBe(['failed' => 3])
        ->and($section->collectedAt?->toIso8601ZuluString())->toBe('2026-08-17T14:00:00Z');
});

it('is immutable: degrading returns a copy', function () {
    $section = sectionAt();

    expect($section->degraded('x'))->not->toBe($section)
        ->and($section->quality)->toBe(Quality::Full)
        ->and($section->reason)->toBeNull();
});
