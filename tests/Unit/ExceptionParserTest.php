<?php

use Ultraviolettes\Constellation\Support\ExceptionParser;
use Ultraviolettes\Constellation\Support\FailureSignature;

/**
 * `(string) $throwable` prefixes the string with the *previous* exceptions, so
 * the block that matters is the last one. A QueryException almost always
 * carries a PDOException: reading the first block would give the wrong class
 * and a different message, hence a signature diverging from mode A for the very
 * same incident (S23).
 */
function chainedException(): string
{
    return "PDOException: SQLSTATE[HY000] [2002] Connection refused in /app/x.php:5\n"
        ."Stack trace:\n"
        ."#0 {main}\n"
        ."\n"
        ."Next Illuminate\\Database\\QueryException: SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal' in /app/y.php:7\n"
        ."Stack trace:\n"
        .'#0 {main}';
}

it('takes the last segment of a chained exception, never the first', function () {
    expect(ExceptionParser::parse(chainedException()))->toBe([
        'class' => 'Illuminate\Database\QueryException',
        'message' => "SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal'",
    ]);
});

it('gives mode B the very signature mode A would compute for the same incident', function () {
    $parsed = ExceptionParser::parse(chainedException());

    $modeB = FailureSignature::makeSignature(
        $parsed['class'],
        'App\Jobs\SyncInvoices',
        FailureSignature::normalizeMessage($parsed['message']),
    );

    // Mode A reads `exception_class` and `exception_message` straight from the
    // plugin's columns; both paths must land on the frozen vector of ADR 003 §5.
    $modeA = FailureSignature::makeSignature(
        'Illuminate\Database\QueryException',
        'App\Jobs\SyncInvoices',
        FailureSignature::normalizeMessage("SQLSTATE[HY000] [2002] Connection refused to 'db.acme.internal'"),
    );

    expect($modeB)->toBe($modeA)
        ->toBe('f88a4427bfec617dec0d7b1585fbaa683fea8bc54a8ad56344ae6ad55fd1f873');
});

it('reads a lone exception with no previous one', function () {
    $exception = "RuntimeException: Timed out after 30 seconds (attempt 3 of 5) in /app/x.php:5\n"
        ."Stack trace:\n"
        .'#0 {main}';

    expect(ExceptionParser::parse($exception))->toBe([
        'class' => 'RuntimeException',
        'message' => 'Timed out after 30 seconds (attempt 3 of 5)',
    ]);
});

it('reads the empty-message form PHP writes as "Class in /file:42"', function () {
    $exception = "App\\Exceptions\\Silent in /var/www/app/Jobs/Foo.php:42\n"
        ."Stack trace:\n"
        .'#0 {main}';

    expect(ExceptionParser::parse($exception))->toBe([
        'class' => 'App\Exceptions\Silent',
        'message' => '',
    ]);
});

it('keeps a multi-line message whole', function () {
    $exception = "Illuminate\\Database\\QueryException: SQLSTATE[42S02]\nSQL: select *\nfrom missing in /app/y.php:7\n"
        ."Stack trace:\n"
        .'#0 {main}';

    expect(ExceptionParser::parse($exception)['message'])->toBe("SQLSTATE[42S02]\nSQL: select *\nfrom missing");
});

it('survives a string it cannot make sense of', function (?string $exception) {
    expect(ExceptionParser::parse($exception))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["\n  \n"],
    'no location suffix' => ['RuntimeException: boom'],
    'plain prose' => ['something went very wrong'],
]);

it('reads the job class from payload.displayName, the value mode A stores in name', function () {
    $payload = json_encode(['displayName' => 'App\Jobs\SyncInvoices', 'job' => 'Illuminate\Queue\CallQueuedHandler@call']);

    expect(ExceptionParser::jobClassFromPayload($payload))->toBe('App\Jobs\SyncInvoices');
});

it('returns no job class rather than a wrong one', function (?string $payload) {
    expect(ExceptionParser::jobClassFromPayload($payload))->toBeNull();
})->with([
    'null' => [null],
    'not json' => ['{oops'],
    'no displayName' => ['{"job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call"}'],
    'displayName is not a string' => ['{"displayName":42}'],
    'json scalar' => ['"App\\\\Jobs\\\\SyncInvoices"'],
]);
