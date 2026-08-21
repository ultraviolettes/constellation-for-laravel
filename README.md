# Constellation for Laravel

Send a periodic health snapshot of your Laravel application to
[Constellation](https://app.constellation.dev).

No migration, no model, no route, no view. `composer require`, one key in
`.env`, done.

## Installation

```bash
composer require ultraviolettes/constellation-for-laravel
```

```dotenv
CONSTELLATION_TOKEN=your-environment-token
```

The package registers its own scheduled task (every 15 minutes by default), so
there is nothing to add to `routes/console.php`.

Publish the config only if you need to change something:

```bash
php artisan vendor:publish --tag=constellation-config
```

## Checking your installation

```bash
php artisan monitor:send --dry-run --pretty   # print the payload, send nothing
php artisan monitor:send --sync               # send immediately and print the outcome
php artisan monitor:send                      # queue the send (what the scheduler does)
```

## What leaves your server

A few kilobytes of JSON every fifteen minutes: framework and PHP versions,
debug mode, pending migrations, and aggregated queue failures. Exception
messages are normalised — quoted strings, UUIDs, hashes and numbers are
replaced by placeholders — so **no data of your own users ever leaves your
server**. No job payloads, no stack traces, no query strings.

## Sections

| Section | Content |
|---|---|
| `app` | PHP and Laravel versions, environment, debug mode, pending migrations, config and route caches |
| `jobs` | Processed and failed totals over the window, plus the top failure groups |

The `jobs` section reads the tables of
[`croustibat/filament-jobs-monitor`](https://github.com/ultraviolettes/filament-jobs-monitor)
when they are present, and falls back to `failed_jobs` otherwise. Without the
plugin there is no source for the number of processed jobs, so the section is
honestly reported as `quality: degraded`.

## Writing your own collector

```php
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\{Section, Status, Window};

final class SeoCollector implements Collector
{
    public function key(): string
    {
        return 'seo';
    }

    public function collect(Window $window): Section
    {
        return Section::make($this->key(), Status::Ok, ['broken_links' => 0], now()->toImmutable());
    }
}
```

Add the class to `config('constellation.collectors')`. The ingest endpoint
stores unknown sections as they are — no backend deployment is needed.

## Events

`SnapshotBuilt`, `SnapshotSent`, `SnapshotRejected` and `SnapshotSendFailed`
let your application react without the SDK deciding for you. A snapshot that
cannot be delivered is abandoned after two attempts with a warning in the log:
a snapshot is a state, not a log, and the next one carries the same truth.

## License

MIT.
