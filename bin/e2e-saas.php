<?php

/**
 * The SaaS side of bin/e2e. Boots a checkout of ultraviolettes/constellation
 * from the outside — nothing in it is modified — against the database named by
 * DB_DATABASE, and either:
 *
 *   setup                       creates a team, project and environment with the
 *                               SaaS factories and prints the environment id;
 *   verify <env-id> <idem-key>  checks what the accepted snapshot left in
 *                               `snapshots` and `snapshot_sections`, prints it,
 *                               and exits non-zero on the first mismatch.
 *
 * Usage: php bin/e2e-saas.php <saas-path> setup|verify …
 */

use App\Models\Environment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

[$script, $saas, $command] = array_pad($argv, 3, null);

if (! is_string($saas) || ! is_file($saas.'/bootstrap/app.php')) {
    fwrite(STDERR, "Usage: php {$script} <saas-path> setup|verify <environment-id> <idempotency-key>\n");
    exit(2);
}

require $saas.'/vendor/autoload.php';

$app = require $saas.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function fail(string $message): never
{
    fwrite(STDERR, "e2e: {$message}\n");
    exit(1);
}

if ($command === 'setup') {
    $environment = Environment::factory()->create(['name' => 'e2e-'.Str::lower(Str::random(6))]);

    echo $environment->getKey(), "\n";
    exit(0);
}

if ($command !== 'verify') {
    fail("unknown command [{$command}]");
}

$environmentId = (int) ($argv[3] ?? 0);
$key = (string) ($argv[4] ?? '');

$snapshot = DB::table('snapshots')
    ->where('environment_id', $environmentId)
    ->where('idempotency_key', $key)
    ->first();

if ($snapshot === null) {
    fail("no snapshot with idempotency key [{$key}] for environment [{$environmentId}]");
}

$sections = DB::table('snapshot_sections')
    ->where('snapshot_id', $snapshot->id)
    ->orderBy('id')
    ->get(['key', 'status', 'quality', 'reason', 'collected_at', 'data']);

$environment = DB::table('environments')->where('id', $environmentId)->first();
$queues = json_decode((string) $sections->firstWhere('key', 'queues')?->data, true);
$row = $queues['queues'][0] ?? [];

echo json_encode([
    'snapshot' => [
        'id' => $snapshot->id,
        'sdk_version' => $snapshot->sdk_version,
        'schema_version' => $snapshot->schema_version,
        'window_to' => $snapshot->window_to,
        'payload_bytes' => $snapshot->payload_bytes,
    ],
    'sections' => $sections->map(fn ($section) => [
        'key' => $section->key,
        'status' => $section->status,
        'quality' => $section->quality,
        'reason' => $section->reason,
    ])->all(),
    'queues_row' => $row,
    'environment' => [
        'remote_name' => $environment->remote_name,
        'last_status' => $environment->last_status,
        'last_ingest_error' => $environment->last_ingest_error,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$keys = $sections->pluck('key')->all();

if ($keys !== ['app', 'jobs', 'queues', 'backups', 'schedule']) {
    fail('unexpected section keys: '.implode(', ', $keys));
}

if (! is_string($snapshot->sdk_version) || $snapshot->sdk_version === '') {
    fail('sdk_version was not read from the User-Agent');
}

$stored = $sections->firstWhere('key', 'queues');

if ($stored->status !== 'critical' || ! str_contains((string) $stored->reason, 'queue_driver_unsupported:redis')) {
    fail("queues section stored as [{$stored->status}] / [{$stored->reason}]");
}

foreach (['last_queued_at', 'last_started_at', 'stalled'] as $field) {
    if (! array_key_exists($field, $row)) {
        fail("queues row lost [{$field}] on the way in");
    }
}

if ($row['stalled'] !== true) {
    fail('queues row did not keep stalled: true');
}

if ($environment->last_ingest_error !== null) {
    fail("environment carries an ingest error [{$environment->last_ingest_error}]");
}

echo "e2e: OK\n";
