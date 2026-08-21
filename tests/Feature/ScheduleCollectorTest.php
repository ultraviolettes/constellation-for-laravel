<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskHistory;
use Ultraviolettes\Constellation\Collectors\Schedule\TaskIdentity;
use Ultraviolettes\Constellation\Collectors\ScheduleCollector;

/**
 * The schedule collector holds no table (ADR 004 T3): the inventory is the live
 * scheduler, the cache holds execution history only. These tests read the
 * section exactly as it goes on the wire.
 */
beforeEach(function () {
    config()->set('constellation.token', 'schedule-token');
    // Not the array store: `cache_driver_array` would ride on every section and
    // mask the reasons under test (S20).
    useCursorCache();
});

function scheduleSection(?string $to = null): array
{
    return sectionOf(ScheduleCollector::class, windowAt($to ?? '2026-08-21T14:00:00Z'));
}

/**
 * @return array<string, array<string, mixed>>
 */
function tasksById(array $section): array
{
    $tasks = [];

    foreach ($section['data']['tasks'] as $task) {
        $tasks[$task['id']] = $task;
    }

    return $tasks;
}

function taskOf(array $section, string $command): array
{
    foreach ($section['data']['tasks'] as $task) {
        if ($task['command'] === $command) {
            return $task;
        }
    }

    throw new RuntimeException("No task named [{$command}] in the section.");
}

it('reads the inventory live from the scheduler, and persists none of it', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();
    $schedule->command('queue:prune-failed')->daily();

    $section = scheduleSection();

    expect($section['data']['tasks'])->toHaveCount(2)
        ->and(taskOf($section, 'php artisan inspire'))->toMatchArray([
            'expression' => '0 * * * *',
            'timezone' => 'UTC',
            'background' => false,
        ])
        ->and(taskOf($section, 'php artisan queue:prune-failed')['expression'])->toBe('0 0 * * *');
});

it('drops a task the client removed from the schedule on the very next snapshot', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();
    $schedule->command('queue:prune-failed')->daily();

    expect(scheduleSection()['data']['tasks'])->toHaveCount(2);

    $kept = emptySchedule();
    $kept->command('inspire')->hourly();

    $section = scheduleSection();

    expect($section['data']['tasks'])->toHaveCount(1)
        ->and($section['data']['tasks'][0]['command'])->toBe('php artisan inspire');
});

it('caches the history of a task and never its command or its expression', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly();
    $event->exitCode = 0;

    event(new ScheduledTaskFinished($event, 1.84));

    $cached = readTaskHistory(TaskIdentity::of($event));

    // Duplicating the planning in the cache would create a second truth, and it
    // would diverge the day the client edits an expression (T3).
    expect(array_keys($cached))->toBe(array_keys(TaskHistory::EMPTY))
        ->and($cached['exit_code'])->toBe(0)
        ->and(json_encode($cached))->not->toContain('inspire')
        ->not->toContain('0 * * * *');
});

it('records a finished foreground run with its runtime and its exit code', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly();
    // `Event::finish()` has run: this is what tells a real run from an overlap.
    $event->exitCode = 0;

    event(new ScheduledTaskFinished($event, 1.84));

    $task = taskOf(scheduleSection(), 'php artisan inspire');

    expect($task['last_ran_at'])->not->toBeNull()
        ->and($task['last_duration_ms'])->toBe(1840)
        ->and($task['last_exit_code'])->toBe(0)
        ->and($task['last_overlapped_at'])->toBeNull();
});

it('treats a foreground task finishing with no exit code as an overlap, not a run', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->everyMinute();

    // `Event::run()` returns before `finish()` when the mutex is taken, so
    // `exitCode` stays null while `Finished` is emitted all the same (T7).
    expect($event->exitCode)->toBeNull()
        ->and($event->runInBackground)->toBeFalse();

    event(new ScheduledTaskFinished($event, 0.0));

    $task = taskOf(scheduleSection(), 'php artisan inspire');

    // The whole point: a task stuck on a stale mutex must never read as green.
    expect($task['last_ran_at'])->toBeNull()
        ->and($task['last_duration_ms'])->toBeNull()
        ->and($task['last_exit_code'])->toBeNull()
        ->and($task['last_overlapped_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('warns on a task whose last trace is an overlap', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->everyMinute();

    event(new ScheduledTaskFinished($event, 0.0));

    expect(scheduleSection()['status'])->toBe('warning');
});

it('reads a background run in two events, the exit code coming from the second', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly()->runInBackground();

    event(new ScheduledTaskFinished($event, 0.02));

    $task = taskOf(scheduleSection(), 'php artisan inspire');

    // `$runtime` only measures the spawn here, and the exit code is not known yet.
    expect($task['background'])->toBeTrue()
        ->and($task['last_ran_at'])->not->toBeNull()
        ->and($task['last_duration_ms'])->toBeNull()
        ->and($task['last_exit_code'])->toBeNull();

    $event->exitCode = 3;
    event(new ScheduledBackgroundTaskFinished($event));

    $task = taskOf(scheduleSection(), 'php artisan inspire');

    expect($task['last_exit_code'])->toBe(3)
        ->and($task['last_ran_at'])->not->toBeNull();
});

it('records a failure by class alone, never by message', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly();

    event(new ScheduledTaskFailed($event, new RuntimeException('connection to db.acme.internal refused')));

    $section = scheduleSection();
    $task = taskOf($section, 'php artisan inspire');

    expect($task['last_failed_at'])->not->toBeNull()
        ->and($task['last_exception_class'])->toBe(RuntimeException::class)
        ->and($task['last_exit_code'])->toBe(1)
        ->and(json_encode($section))->not->toContain('db.acme.internal');
});

it('warns on a non-zero exit code', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly();
    $event->exitCode = 1;

    event(new ScheduledTaskFinished($event, 0.4));

    expect(scheduleSection()['status'])->toBe('warning');
});

it('records a skip as a skip, and never as a miss', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly();

    writeObservedSince(CarbonImmutable::parse('2026-08-21T09:00:00Z'));
    event(new ScheduledTaskSkipped($event));

    expect(readTaskHistory(TaskIdentity::of($event))['skipped_at'])->toBeInt();

    writeTaskHistory(TaskIdentity::of($event), ['skipped_at' => CarbonImmutable::parse('2026-08-21T13:30:00Z')->getTimestamp()]);

    $task = taskOf(scheduleSection(), 'php artisan inspire');

    // A `->environments(['production'])` declining is the scheduler coming by,
    // not an absence (T8): counting it as missed would alarm on a healthy app.
    expect($task['last_skipped_at'])->toBe('2026-08-21T13:30:00Z')
        ->and($task['missed'])->toBe(0);
});

it('gives two tasks with the same command but different expressions two identities', function () {
    $schedule = emptySchedule();
    $hourly = $schedule->command('inspire')->hourly();
    $daily = $schedule->command('inspire')->daily();

    expect(TaskIdentity::of($hourly))->not->toBe(TaskIdentity::of($daily))
        ->and(scheduleSection()['data']['tasks'])->toHaveCount(2);
});

it('rejects mutexName as an identity: two named closures collide there, not here', function () {
    $schedule = emptySchedule();
    $hourly = $schedule->call(fn () => null)->name('reports')->hourly();
    $daily = $schedule->call(fn () => null)->name('reports')->daily();

    // `CallbackEvent::mutexName()` hashes the description alone: it ignores the
    // expression, so both tasks share one mutex name (T6).
    expect($hourly->mutexName())->toBe($daily->mutexName())
        ->and(TaskIdentity::of($hourly))->not->toBe(TaskIdentity::of($daily));

    $section = scheduleSection();

    expect($section['data']['tasks'])->toHaveCount(2)
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBeNull();
});

it('merges two identical registrations into one entry', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();
    $schedule->command('inspire')->hourly();

    // Registering the same task twice is a bug in the client's Kernel (R7); the
    // SDK reports one task rather than inventing a second.
    expect(scheduleSection()['data']['tasks'])->toHaveCount(1);
});

it('says so when anonymous closures cannot be told apart', function () {
    $schedule = emptySchedule();
    $schedule->call(fn () => null)->hourly();
    $schedule->call(fn () => null)->hourly();

    $section = scheduleSection();

    expect($section['data']['tasks'])->toHaveCount(1)
        ->and($section['data']['tasks'][0]['command'])->toBe('Closure')
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(ScheduleCollector::REASON_UNNAMED_CLOSURES)
        ->and($section['status'])->not->toBe('unavailable');
});

it('derives missed from the cron expression against what it last saw', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();

    writeObservedSince(CarbonImmutable::parse('2026-08-21T09:00:00Z'));

    $section = scheduleSection();

    // 10:00, 11:00, 12:00 and 13:00 are due in ]09:00, 13:58:30]; 14:00 is the
    // occurrence in flight, which `grace_seconds` keeps out.
    expect(taskOf($section, 'php artisan inspire'))->toMatchArray([
        'missed' => 4,
        'missed_capped' => false,
    ])
        ->and($section['data']['observed_since'])->toBe('2026-08-21T09:00:00Z')
        ->and($section['status'])->toBe('critical');
});

it('counts missed from the last run rather than from the observation base', function () {
    $schedule = emptySchedule();
    $event = $schedule->command('inspire')->hourly();

    writeObservedSince(CarbonImmutable::parse('2026-08-21T09:00:00Z'));
    writeTaskHistory(TaskIdentity::of($event), [
        'ran_at' => CarbonImmutable::parse('2026-08-21T12:30:00Z')->getTimestamp(),
        'exit_code' => 0,
    ]);

    expect(taskOf(scheduleSection(), 'php artisan inspire')['missed'])->toBe(1);
});

it('keeps the grace window out of the count', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->everyMinute();

    writeObservedSince(CarbonImmutable::parse('2026-08-21T13:56:00Z'));

    expect(taskOf(scheduleSection(), 'php artisan inspire')['missed'])->toBe(2);

    config()->set('constellation.schedule_tasks.grace_seconds', 0);

    // Without the grace, 13:59 and the occurrence running at 14:00 join the count.
    expect(taskOf(scheduleSection(), 'php artisan inspire')['missed'])->toBe(4);
});

it('caps the count rather than reporting a number nobody needs', function () {
    config()->set('constellation.schedule_tasks.missed_cap', 3);

    $schedule = emptySchedule();
    $schedule->command('inspire')->everyMinute();

    writeObservedSince(CarbonImmutable::parse('2026-08-21T13:00:00Z'));

    expect(taskOf(scheduleSection(), 'php artisan inspire'))->toMatchArray([
        'missed' => 3,
        'missed_capped' => true,
    ]);
});

it('reports a whole inventory with no history at all as a normal section', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();
    $schedule->command('queue:prune-failed')->daily();

    $section = scheduleSection();

    // Just after `composer require`, before the scheduler has run once: the
    // collector *did* look, and knows exactly which tasks exist (T11).
    expect($section['status'])->toBe('ok')
        ->and($section['quality'])->toBe('full')
        ->and($section['reason'])->toBeNull()
        ->and($section['collected_at'])->toBe('2026-08-21T14:00:00Z')
        ->and($section['data']['tasks'])->toHaveCount(2);

    foreach ($section['data']['tasks'] as $task) {
        expect($task['last_ran_at'])->toBeNull()
            ->and($task['missed'])->toBeNull()
            ->and($task['missed_capped'])->toBeFalse();
    }
});

it('opens the observation window on the first snapshot and keeps it after', function () {
    emptySchedule()->command('inspire')->hourly();

    expect(scheduleSection()['data']['observed_since'])->toBe('2026-08-21T14:00:00Z');

    // Written with `add()`: a later snapshot must not push the base forward, or
    // a monthly task would never have a window to be counted missed in (T9).
    expect(scheduleSection('2026-08-21T18:00:00Z')['data']['observed_since'])
        ->toBe('2026-08-21T14:00:00Z');
});

it('degrades on the array driver instead of claiming it saw nothing', function () {
    config()->set('constellation.cache.store', 'array');

    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();

    $section = scheduleSection();

    // Nothing survives between `schedule:run` and the collecting process, but
    // the inventory is still a complete answer (S20).
    expect($section['status'])->toBe('ok')
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(ScheduleCollector::REASON_ARRAY_DRIVER)
        ->and($section['data']['tasks'])->toHaveCount(1);
});

it('reports the scheduler as unavailable only when there is no scheduler at all', function () {
    unset(app()[Schedule::class]);

    $section = scheduleSection();

    expect($section['status'])->toBe('unavailable')
        ->and($section['quality'])->toBe('degraded')
        ->and($section['reason'])->toBe(ScheduleCollector::REASON_NOT_DEFINED)
        ->and($section['collected_at'])->toBeNull()
        ->and($section['data'])->toBe([]);
});

it('keeps an exec command line out of the payload by default', function () {
    $schedule = emptySchedule();
    $schedule->exec('/usr/local/bin/mysqldump -pS3CR3T acme > /srv/backups/acme.sql')->daily();

    $section = scheduleSection();
    $json = json_encode($section);

    // Rule 4 of §2: no client data leaves. A shell line carries secrets and
    // server paths (T12).
    expect($section['data']['tasks'][0]['command'])->toBe('mysqldump')
        ->and($json)->not->toContain('S3CR3T')
        ->and($json)->not->toContain('/srv/backups')
        ->and($json)->not->toContain('/usr/local/bin');
});

it('sends the exec command line when the client asks for it explicitly', function () {
    config()->set('constellation.schedule_tasks.send_exec_command', true);

    $schedule = emptySchedule();
    $schedule->exec('/usr/local/bin/mysqldump -pS3CR3T acme')->daily();

    expect(scheduleSection()['data']['tasks'][0]['command'])
        ->toBe('/usr/local/bin/mysqldump -pS3CR3T acme');
});

it('sends an artisan command line as it is, with no host path in it', function () {
    $schedule = emptySchedule();
    $schedule->command('invoices:sync --tenant=3')->hourly();

    $command = scheduleSection()['data']['tasks'][0]['command'];

    expect($command)->toBe('php artisan invoices:sync --tenant=3')
        ->and($command)->not->toContain(PHP_BINARY)
        ->and($command)->not->toContain(base_path());
});

it('clamps the history TTL to the period of the task', function () {
    $schedule = emptySchedule();
    $hourly = $schedule->command('inspire')->hourly();
    $weekly = $schedule->command('queue:prune-failed')->weekly();

    event(new ScheduledTaskFinished($hourly, 0.1));
    event(new ScheduledTaskFinished($weekly, 0.1));

    $ttl = fn (Event $event): int => (int) DB::table('cache')
        ->where('key', 'like', '%'.TaskIdentity::of($event))
        ->value('expiration') - time();

    // A fixed week would erase the history of a rare task between two runs, and
    // report it as never run (T10).
    expect($ttl($hourly))->toBeGreaterThan(TaskHistory::WEEK - 10)
        ->toBeLessThanOrEqual(TaskHistory::WEEK)
        ->and($ttl($weekly))->toBeGreaterThan(3 * 604800 - 10)
        ->toBeLessThanOrEqual(3 * 604800);
});

it('freezes the shape of the schedule data block', function () {
    $schedule = emptySchedule();
    $schedule->command('inspire')->hourly();

    $section = scheduleSection();

    expect(array_keys($section['data']))->toBe(['observed_since', 'tasks'])
        ->and(array_keys($section['data']['tasks'][0]))->toBe([
            'id',
            'command',
            'expression',
            'timezone',
            'background',
            'last_ran_at',
            'last_duration_ms',
            'last_exit_code',
            'last_failed_at',
            'last_exception_class',
            'last_skipped_at',
            'last_overlapped_at',
            'missed',
            'missed_capped',
        ])
        ->and($section['data']['tasks'][0]['id'])->toMatch('/^[0-9a-f]{16}$/');
});
