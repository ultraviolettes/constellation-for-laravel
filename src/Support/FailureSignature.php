<?php

namespace Ultraviolettes\Constellation\Support;

/**
 * Ported verbatim from croustibat/filament-jobs-monitor, Models/FailureGroup.php.
 *
 * Every quirk of this algorithm is intentional and frozen: the two packages must
 * produce byte-identical signatures for the same failure, or a client installing
 * the plugin would split their own failure history in two. Do not "fix" the
 * regexes here — see docs/adr/003 §5 for the frozen test vector.
 */
final class FailureSignature
{
    public static function normalizeMessage(string $message): string
    {
        $normalized = self::replace("/'(?:[^'\\\\]|\\\\.)*'/", "'<str>'", $message);
        $normalized = self::replace('/"(?:[^"\\\\]|\\\\.)*"/', '"<str>"', $normalized);
        $normalized = self::replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $normalized);
        $normalized = self::replace('/\b[0-9a-f]{16,}\b/i', '<hash>', $normalized);
        $normalized = self::replace('/\b\d+(\.\d+)?\b/', '<num>', $normalized);

        return mb_strcut(trim($normalized), 0, 1024);
    }

    public static function makeSignature(string $exceptionClass, ?string $jobClass, string $normalizedMessage): string
    {
        return hash('sha256', $exceptionClass.'|'.($jobClass ?? '').'|'.$normalizedMessage);
    }

    /**
     * Only sanctioned deviation from the reference: a PCRE backtracking limit
     * makes preg_replace return null, and the previous value is kept instead of
     * propagating null. No nominal result changes.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
