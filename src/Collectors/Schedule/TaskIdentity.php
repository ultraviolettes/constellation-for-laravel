<?php

namespace Ultraviolettes\Constellation\Collectors\Schedule;

use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Identity and display form of a scheduled task (ADR 004 T6, T12).
 *
 * `mutexName()` is deliberately not used: an application may override it through
 * `createMutexNameUsing()`, and `CallbackEvent::mutexName()` hashes the description
 * alone — every anonymous closure would collide.
 */
final class TaskIdentity
{
    public static function of(Event $event): string
    {
        return substr(sha1($event->getExpression().'|'.self::displayCommand($event)), 0, 16);
    }

    /**
     * The full, host-independent form. Feeds the identity, never the payload
     * directly: an `exec()` line is redacted by `published()` unless opted in.
     */
    public static function displayCommand(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            return is_string($event->description) && $event->description !== ''
                ? $event->description
                : 'Closure';
        }

        return self::normalize((string) ($event->command ?? ''));
    }

    /**
     * @param  bool  $sendExecCommand  `constellation.schedule_tasks.send_exec_command`
     */
    public static function published(Event $event, bool $sendExecCommand): string
    {
        $command = self::displayCommand($event);

        if ($event instanceof CallbackEvent || $sendExecCommand || str_starts_with($command, 'php artisan ')) {
            return $command;
        }

        // An exec() line is shell written by the client and may carry a secret
        // or a server path: only the base name of its first token leaves.
        $first = strtok(trim($command), " \t");
        $base = $first === false ? '' : basename($first);

        return $base === '' ? 'exec' : $base;
    }

    public static function isAnonymousClosure(Event $event): bool
    {
        return $event instanceof CallbackEvent
            && (! is_string($event->description) || $event->description === '');
    }

    public static function timezone(Event $event, string $fallback): string
    {
        $timezone = $event->timezone;

        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        $name = (string) $timezone;

        return $name === '' ? $fallback : $name;
    }

    /**
     * Ported from `Event::normalizeCommand()`, absent from Laravel 11 (ADR 003 S7).
     * Both static binaries exist unchanged from 11.2 to 13.x.
     */
    private static function normalize(string $command): string
    {
        return str_replace(
            [Application::phpBinary(), Application::artisanBinary()],
            ['php', (string) preg_replace("#['\"]#", '', Application::artisanBinary())],
            $command
        );
    }
}
