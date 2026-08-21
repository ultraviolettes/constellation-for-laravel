<?php

use Ultraviolettes\Constellation\Support\FailureSignature;

/**
 * The frozen test vector of ADR 003 §5, copied verbatim from the reference
 * implementation in croustibat/filament-jobs-monitor. Any future divergence
 * between the two packages must break loudly here rather than silently split a
 * client's failure history in two.
 */
dataset('signature vector', [
    'quoted string and number' => [
        'Illuminate\Database\QueryException',
        'App\Jobs\SyncInvoices',
        "SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal'",
        "SQLSTATE[HY000] [<num>] Connection refused to '<str>'",
        'f88a4427bfec617dec0d7b1585fbaa683fea8bc54a8ad56344ae6ad55fd1f873',
    ],
    'uuid' => [
        'App\Exceptions\PaymentFailed',
        'App\Jobs\ChargeCustomer',
        'Order 550e8400-e29b-41d4-a716-446655440000 could not be charged',
        'Order <uuid> could not be charged',
        '41da4556694719d0a280208aee3c403f4da04304013d25672dfb5487a37cf40e',
    ],
    'repeated numbers' => [
        'RuntimeException',
        'App\Jobs\SendReport',
        'Timed out after 30 seconds (attempt 3 of 5)',
        'Timed out after <num> seconds (attempt <num> of <num>)',
        '27e00b6cf2ce3ae6be2f0442596195838a300ddc6ed3e6afa64b8e5edd8ceed8',
    ],
    'double quotes and null job class' => [
        'ErrorException',
        null,
        'Undefined array key "invoice_id" in /var/www/app/Jobs/Foo.php on line 42',
        'Undefined array key "<str>" in /var/www/app/Jobs/Foo.php on line <num>',
        'baea763c9f707c21e98c3e7309634d696120bd42b792b56c70c26de0a796c910',
    ],
    'hexadecimal hash' => [
        'GuzzleHttp\Exception\ConnectException',
        'App\Jobs\PushWebhook',
        'cURL error 28: Operation timed out after 10000 milliseconds with etag 9f86d081884c7d659a2feaa0c55ad015',
        'cURL error <num>: Operation timed out after <num> milliseconds with etag <hash>',
        '9591e9d30b9ec0f57dc49d3b5232853980cbd7c1c29a60a40e2cb1cd7e7a04fa',
    ],
]);

/**
 * Quirks of the ported algorithm. They are frozen deliberately: "fixing" any of
 * them would diverge from the plugin.
 */
dataset('frozen quirks', [
    'unbalanced quote shifts the match' => [
        "Can't connect to 'localhost'",
        "Can'<str>'localhost'",
    ],
    'word boundary splits semantic versions' => [
        'PHP 8.4.3 / Laravel 12.21.0',
        'PHP <num>.<num> / Laravel <num>.<num>',
    ],
    'iso timestamps are only partly normalised' => [
        'Deadlock found; try restarting transaction at 2026-08-17T14:00:00Z',
        'Deadlock found; try restarting transaction at <num>-<num>-17T14:<num>:00Z',
    ],
]);

it('normalises the frozen vector', function (string $class, ?string $job, string $raw, string $expected) {
    expect(FailureSignature::normalizeMessage($raw))->toBe($expected);
})->with('signature vector');

it('signs the frozen vector', function (string $class, ?string $job, string $raw, string $normalized, string $signature) {
    expect(FailureSignature::makeSignature($class, $job, FailureSignature::normalizeMessage($raw)))
        ->toBe($signature);
})->with('signature vector');

it('keeps the quirks of the reference implementation', function (string $raw, string $expected) {
    expect(FailureSignature::normalizeMessage($raw))->toBe($expected);
})->with('frozen quirks');

it('normalises idempotently', function (string $raw) {
    $once = FailureSignature::normalizeMessage($raw);

    expect(FailureSignature::normalizeMessage($once))->toBe($once);
})->with([
    "SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal'",
    'Order 550e8400-e29b-41d4-a716-446655440000 could not be charged',
    'Timed out after 30 seconds (attempt 3 of 5)',
    'Undefined array key "invoice_id" in /var/www/app/Jobs/Foo.php on line 42',
    'cURL error 28: Operation timed out after 10000 milliseconds with etag 9f86d081884c7d659a2feaa0c55ad015',
    "Can't connect to 'localhost'",
    'PHP 8.4.3 / Laravel 12.21.0',
    'Deadlock found; try restarting transaction at 2026-08-17T14:00:00Z',
]);

it('treats a null job class as an empty string, never the string "null"', function () {
    expect(FailureSignature::makeSignature('RuntimeException', null, 'boom'))
        ->toBe(FailureSignature::makeSignature('RuntimeException', '', 'boom'))
        ->not->toBe(FailureSignature::makeSignature('RuntimeException', 'null', 'boom'));
});

it('truncates the normalised message to 1024 bytes, not characters', function () {
    $message = str_repeat('é', 700);

    expect(strlen(FailureSignature::normalizeMessage($message)))->toBeLessThanOrEqual(1024);
});
