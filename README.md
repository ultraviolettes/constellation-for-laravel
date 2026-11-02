# Constellation for Laravel

Send a periodic health snapshot of your Laravel application to
[Constellation](https://app.constellation.dev).

No migration, no model, no route, no view. `composer require`, one key in
`.env`, done.

## Installation

### 1. Require the package

The package is not on Packagist yet: declare the repository in your
application's `composer.json`, then require it.

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/ultraviolettes/constellation-for-laravel" }
]
```

```bash
composer require ultraviolettes/constellation-for-laravel:dev-main
```

No release is tagged yet, hence `dev-main`; pin a tag as soon as one exists.
If the repository is private to you, Composer needs a GitHub token once:
`composer config --global github-oauth.github.com <token>`.

### 2. Set the environment

```dotenv
# Required: the token of this environment, issued by Constellation.
CONSTELLATION_TOKEN=cnst_ing_...

# Optional, with their defaults.
CONSTELLATION_ENABLED=true
CONSTELLATION_ENDPOINT=https://app.constellation.dev/api/v1/snapshots
CONSTELLATION_SCHEDULE=true            # register the send in your scheduler
CONSTELLATION_CRON="*/15 * * * *"
CONSTELLATION_QUEUE_CONNECTION=        # empty: your default connection
CONSTELLATION_QUEUE=                   # empty: that connection's default queue
CONSTELLATION_CACHE_STORE=             # empty: your default cache store
```

One token per environment: production and staging each get their own. A
production token copied into a staging `.env` is refused by the server
(`environment_mismatch`) rather than mixing the two series.

The SDK keeps a little state between two snapshots — the window cursor, the
schedule history, the queue activity — in your **cache**, never in a table.
Use a store every process shares (Redis, database, Memcached, DynamoDB; `file`
on a single server). On `array`, nothing survives between processes: the
`jobs` and `schedule` sections say so with `quality: degraded`, and the
dead-worker signal stays `null`.

### 3. Run the scheduler and a worker

The package registers its own scheduled task (every 15 minutes by default):
there is nothing to add to `routes/console.php`. Your server still has to run
the Laravel scheduler, as for any scheduled task:

```cron
* * * * * cd /path/to/your/app && php artisan schedule:run >> /dev/null 2>&1
```

The task builds the snapshot and **queues** its sending: a worker must be
running on that connection (`CONSTELLATION_QUEUE_CONNECTION` /
`CONSTELLATION_QUEUE` to give it its own). When the snapshot it just built
shows that very queue blocked — `stalled`, or a job older than
`critical_age_seconds` — the task sends synchronously instead: the snapshot
reporting a dead worker must not wait behind it. A snapshot that still sat in
the queue for more than 30 minutes is dropped, not sent late: a snapshot is a
state, and the next one carries the same truth.

If the scheduler itself stops, no snapshot leaves at all — Constellation
notices the silence after 45 minutes and opens an incident; no section of the
snapshot can report its own absence.

### 4. Restart your workers — after install and after every upgrade

```bash
php artisan queue:restart        # or: php artisan horizon:terminate
```

A queue worker is a long-running process: it keeps the code it booted with.
Until it restarts, it runs the previous version of the package — or none at
all on a fresh install, so the dead-worker detection below never sees it start
a job. `php artisan about` points at exactly this when it happens. Many deploy
scripts already run `queue:restart` on every deploy: check that yours does.

### Checking your installation

```bash
php artisan about --only=constellation        # token, endpoint, cache, last send, workers
php artisan monitor:send --dry-run --pretty   # print the payload, send and write nothing
php artisan monitor:send --sync               # send immediately and print the outcome
php artisan monitor:send                      # queue the send (what the scheduler does)
```

The `about` section never prints the token, only whether there is one:

```
  Constellation ............................................................
  Cache store .................................................. redis (redis)
  Enabled ...................................................... ENABLED
  Endpoint ...................... https://app.constellation.dev/api/v1/snapshots
  Last successful send ............ 2026-11-02 14:00:02 UTC (3 minutes ago)
  Queue workers ............................................ job starts observed
  Token ........................................................ configured
  Unavailable collectors ..................... backups (collector_not_installed)
  Version ........................................................ 0.1.0
```

Publish the config only if you need to change something:

```bash
php artisan vendor:publish --tag=constellation-config
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
| `queues` | Per queue: depth, age of the oldest pending job, throughput and failures over the window, dead-worker signal |
| `backups` | Per `spatie/laravel-backup` destination: health, last successful backup, count, size |
| `schedule` | Per scheduled task: last run, duration, exit code, missed runs |

The `jobs` section reads the tables of
[`croustibat/filament-jobs-monitor`](https://github.com/ultraviolettes/filament-jobs-monitor)
when they are present, and falls back to `failed_jobs` otherwise. Without the
plugin there is no source for the number of processed jobs, so the section is
honestly reported as `quality: degraded`.

### Dead-worker detection

The surest sign of a dead worker is a pending job that keeps ageing. On the
`database` driver, and on Redis from Laravel 12.20, the SDK reads that age
directly (`oldest_pending_age_seconds`). Redis and SQS on Laravel 11 and 12
before 12.20 cannot give it — the field stays `null` and the section says
`queue_driver_unsupported:<driver>`.

For every driver, the SDK therefore also listens to `JobQueued` and
`JobProcessing` and keeps two timestamps per queue in your cache. Each row of
the `queues` section carries:

| Field | Meaning |
|---|---|
| `last_queued_at` | last job put on this queue (delayed jobs are ignored) |
| `last_started_at` | last job a worker started on it |
| `stalled` | `true`: jobs were queued after the last start, the queue still holds work, and no job started for `queues.stalled_after_seconds` (1 hour); `false`: no stall; `null`: cannot tell |

A stall turns the section `critical`, like a pending job older than
`critical_age_seconds`. One snapshot is enough on the SDK side: through two
timestamps, a dead worker and a queue idle for hours that received a job a
second before the snapshot look the same, and it is the server that tells
them apart — it opens an incident only on two consecutive `critical`
snapshots. A queue whose depth reads zero is never stalled, whatever the
timestamps say (`queue:clear`, a drained queue). Stalled queues are listed
first and never cut by `queues.max`.

`stalled` is `null` on the `array` cache, when the cache cannot be read, and
when jobs are queued but no worker was ever seen starting one — a worker that
predates the installation looks exactly like that, and calling it dead would
be a false alarm on every fresh install (see step 4 above).

Why one hour: a single job holds the only worker of its queue for as long as
it runs, and exports, imports or backups commonly take tens of minutes; a
shorter threshold would call a busy worker dead. With the server's
confirmation, an incident opens at most about 1 h 30 after the last start, well within
what a maintenance report needs. Tune it in `config/constellation.php`.

Cost, and your transactions: the listeners never write while you hold a
database transaction open — with the `database` cache store, a write there
would take a lock your other dispatches wait on, and on PostgreSQL a failing
write would abort your transaction. They keep the timestamp in memory and
write it after the response is sent (HTTP), or at once outside any
transaction and otherwise after your commit (console and workers). At most one
write per queue and per minute, across processes: the stored value is read
first and only replaced when it is a minute old. Nothing is registered at all
when the package is disabled, has no token, or the `queues` collector is
removed.

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

## Development

```bash
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pest
```

The transaction tests of the dead-worker signal also run on PostgreSQL when
given a throwaway database (CI does):

```bash
CONSTELLATION_TEST_PGSQL_URL=pgsql://user:secret@127.0.0.1:5432/sdk_pg_test vendor/bin/pest
```

### End-to-end test against a real Constellation server

`vendor/bin/pest` skips it. `bin/e2e` runs it against a local checkout of
`ultraviolettes/constellation`, without modifying its code, and refuses any
database that is not on this machine:

```bash
CONSTELLATION_SAAS_PATH=../constellation bin/e2e
```

It migrates the SaaS database (`CONSTELLATION_SAAS_DB`, default
`constellation_d`), creates a throwaway team, project and environment with the
SaaS factories, issues its token with `php artisan ingest:token:issue`, serves
the SaaS on `127.0.0.1:8004` (`CONSTELLATION_SAAS_PORT`), sends a real snapshot
from a Testbench application — a confirmed dead worker on a Redis queue that
cannot report its head age included — then checks the rows in `snapshots` and
`snapshot_sections`, deletes the throwaway team (everything else cascades)
and its owner, and stops the server. Plain HTTP is accepted locally
because the SaaS ships with `INGEST_REQUIRE_TLS=false` and runs with
`APP_ENV=local`; nothing is bypassed.

Against a server you already run:

```bash
CONSTELLATION_E2E_ENDPOINT=http://127.0.0.1:8004/api/v1/snapshots \
CONSTELLATION_E2E_TOKEN=cnst_ing_... \
vendor/bin/pest --group=e2e
```

## License

MIT.
