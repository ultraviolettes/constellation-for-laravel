<?php

use Ultraviolettes\Constellation\Collectors\BackupsCollector;

/**
 * The one thing that cannot be tested next to its opposite: a class that has
 * been declared cannot be undeclared, so the "package absent" path needs a
 * process that has never loaded the doubles of `BackupsInstalledTest`.
 *
 * This file is named to sort before it, and asserts the precondition out loud
 * rather than skipping quietly if some future test file loads them first.
 */
beforeEach(function () {
    config()->set('constellation.token', 'backups-token');
});

it('runs before anything has taught the process about spatie/laravel-backup', function () {
    expect(class_exists(BackupsCollector::DESTINATION))->toBeFalse();
});

it('reports backups as unavailable when the package is not installed', function () {
    $section = sectionOf(BackupsCollector::class);

    // "Your application has no backup configured" is a fact worth selling; the
    // package being absent is an environment cause, and the only one here (T20, T25).
    expect($section)->toBe([
        'status' => 'unavailable',
        'quality' => 'degraded',
        'reason' => BackupsCollector::REASON_NOT_INSTALLED,
        'collected_at' => null,
        'data' => [],
    ]);
});

it('does not even look at the backup configuration when the package is absent', function () {
    config()->set('backup.monitor_backups', [['name' => 'acme', 'disks' => ['s3']]]);

    expect(sectionOf(BackupsCollector::class)['reason'])->toBe(BackupsCollector::REASON_NOT_INSTALLED);
});
