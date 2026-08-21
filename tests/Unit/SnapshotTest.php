<?php

use Carbon\CarbonImmutable;
use Ultraviolettes\Constellation\Snapshot\Snapshot;
use Ultraviolettes\Constellation\Snapshot\Window;

function testWindow(): Window
{
    return new Window(
        CarbonImmutable::parse('2026-08-17T13:45:00Z'),
        CarbonImmutable::parse('2026-08-17T14:00:00Z'),
    );
}

it('generates the idempotency key in the constructor, before any I/O', function () {
    $snapshot = new Snapshot(['schema' => 1], testWindow());

    expect($snapshot->idempotencyKey)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('gives each snapshot its own idempotency key', function () {
    expect((new Snapshot(['schema' => 1], testWindow()))->idempotencyKey)
        ->not->toBe((new Snapshot(['schema' => 1], testWindow()))->idempotencyKey);
});

it('freezes the body at construction so both attempts carry the same digest', function () {
    $snapshot = new Snapshot(['schema' => 1, 'sent_at' => '2026-08-17T14:00:00Z'], testWindow());

    $first = $snapshot->body;
    $second = $snapshot->body;

    expect($first)->toBe($second)
        ->and(hash('sha256', $first))->toBe(hash('sha256', $second))
        ->and($first)->toContain('"sent_at":"2026-08-17T14:00:00Z"');
});

it('encodes with the frozen JSON flags', function () {
    $snapshot = new Snapshot([
        'url' => 'https://acme.test/app',
        'name' => 'Café Constellation',
        'success_rate' => 1.0,
    ], testWindow());

    expect($snapshot->body)
        ->toContain('"url":"https://acme.test/app"')   // JSON_UNESCAPED_SLASHES
        ->toContain('"name":"Café Constellation"')     // JSON_UNESCAPED_UNICODE
        ->toContain('"success_rate":1.0');             // JSON_PRESERVE_ZERO_FRACTION
});

it('reports window.to as the cursor the send job will store', function () {
    expect((new Snapshot(['schema' => 1], testWindow()))->windowTo())->toBe('2026-08-17T14:00:00Z');
});

it('indents the dry-run json without changing the payload', function () {
    $snapshot = new Snapshot(['schema' => 1, 'sections' => ['app' => ['status' => 'ok']]], testWindow());

    expect($snapshot->toPrettyJson())->toContain("\n    ")
        ->and(json_decode($snapshot->toPrettyJson(), true))->toBe(json_decode($snapshot->body, true));
});

it('refuses a payload it cannot encode rather than sending half of it', function () {
    expect(fn () => new Snapshot(['broken' => "\xB1\x31"], testWindow()))->toThrow(JsonException::class);
});
