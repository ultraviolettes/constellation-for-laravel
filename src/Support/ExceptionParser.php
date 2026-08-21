<?php

namespace Ultraviolettes\Constellation\Support;

/**
 * Reads back what `(string) $throwable` wrote into `failed_jobs.exception`.
 */
final class ExceptionParser
{
    /**
     * The last segment is the exception that was actually thrown: PHP prefixes
     * the string with the *previous* exceptions, and a QueryException almost
     * always carries a PDOException. Reading the first block would produce a
     * different signature than mode A for the very same incident.
     *
     * @return array{class: string, message: string}|null
     */
    public static function parse(?string $exception): ?array
    {
        if ($exception === null || trim($exception) === '') {
            return null;
        }

        $segments = explode("\n\nNext ", $exception);
        $last = (string) end($segments);

        $head = explode("\nStack trace:", $last, 2)[0];

        if (preg_match('/^(?<class>[^\s:]+): (?<message>.*?) in \S+:\d+$/s', $head, $matches) === 1) {
            return ['class' => $matches['class'], 'message' => $matches['message']];
        }

        // PHP writes `Class in /file:42` when the message is empty.
        if (preg_match('/^(?<class>[^\s:]+) in \S+:\d+$/s', $head, $matches) === 1) {
            return ['class' => $matches['class'], 'message' => ''];
        }

        return null;
    }

    /**
     * `payload.displayName` is exactly what `$job->resolveName()` writes into
     * `queue_monitors.name` in mode A.
     */
    public static function jobClassFromPayload(?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['displayName']) || ! is_string($decoded['displayName'])) {
            return null;
        }

        return $decoded['displayName'];
    }
}
