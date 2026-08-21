<?php

use Ultraviolettes\Constellation\Constellation;

/**
 * @return array<string, string> Absolute path => file contents, for every source file.
 */
function sourceFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('never mentions the plugin it is meant to work without', function (string $needle) {
    foreach (sourceFiles() as $path => $contents) {
        // The ported algorithm may cite its source in a comment; nothing may depend on it.
        $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents);

        expect($code)->not->toContain($needle, "[$path] must not depend on [$needle].");
    }
})->with(['Filament', 'croustibat', 'filament-jobs-monitor']);

it('declares no dependency on filament nor on the plugin', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect(array_keys($composer['require']))->toBe([
        'php', 'ext-json', 'guzzlehttp/guzzle', 'illuminate/contracts', 'illuminate/support', 'ramsey/uuid',
    ]);
});

it('uses no framework API introduced after Laravel 11.0', function (string $needle) {
    foreach (sourceFiles() as $path => $contents) {
        expect($contents)->not->toContain($needle, "[$path] uses [$needle], which the SDK cannot rely on.");
    }
})->with([
    'the Context facade' => ['Support\Facades\Context'],
    'the Schedule facade' => ['Support\Facades\Schedule'],
    'flexible caching' => ['Cache::flexible'],
    'uuid7' => ['uuid7'],
    'the Scope attribute' => ['#[Scope]'],
]);

it('ships no migration, no model, no view, no asset and no route', function (string $directory) {
    expect(dirname(__DIR__, 2).'/'.$directory)->not->toBeDirectory();
})->with(['database', 'resources', 'routes', 'public', 'src/Models']);

it('leaves no debugging statement behind', function (string $needle) {
    foreach (sourceFiles() as $path => $contents) {
        expect($contents)->not->toContain($needle, "[$path] still calls [$needle].");
    }
})->with(['dd(', 'dump(', 'var_dump(', 'ray(', 'print_r(']);

it('states the sdk version the ingest endpoint records', function () {
    expect(Constellation::VERSION)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(Constellation::SCHEMA)->toBe(1);
});

it('opens the User-Agent with the name/version segment the server parses', function () {
    expect(Constellation::userAgent('12.0.0', '8.4.3'))
        ->toBe('constellation-for-laravel/'.Constellation::VERSION.' (Laravel/12.0.0; PHP/8.4.3)');
});
