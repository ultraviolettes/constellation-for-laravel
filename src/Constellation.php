<?php

namespace Ultraviolettes\Constellation;

final class Constellation
{
    /**
     * The first segment of the User-Agent is a contract with the ingest
     * endpoint: it is the only source of `snapshots.sdk_version`.
     */
    public const VERSION = '0.1.0';

    public const SCHEMA = 1;

    public static function userAgent(string $laravelVersion, string $phpVersion): string
    {
        return sprintf(
            'constellation-for-laravel/%s (Laravel/%s; PHP/%s)',
            self::VERSION,
            $laravelVersion,
            $phpVersion
        );
    }
}
