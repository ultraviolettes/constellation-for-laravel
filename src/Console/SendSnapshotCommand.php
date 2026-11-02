<?php

namespace Ultraviolettes\Constellation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ultraviolettes\Constellation\Collectors\Queues\QueueActivity;
use Ultraviolettes\Constellation\Events\SnapshotRejected;
use Ultraviolettes\Constellation\Events\SnapshotSendFailed;
use Ultraviolettes\Constellation\Events\SnapshotSent;
use Ultraviolettes\Constellation\Jobs\SendSnapshot;
use Ultraviolettes\Constellation\Snapshot\SnapshotBuilder;

final class SendSnapshotCommand extends Command
{
    protected $signature = 'monitor:send
                            {--sync : Run the send immediately instead of queueing it}
                            {--dry-run : Build the snapshot and print it without sending or moving the cursor}
                            {--pretty : Indent the --dry-run JSON}';

    protected $description = 'Build and send a Constellation health snapshot.';

    public function handle(SnapshotBuilder $builder, Bus $bus, Events $events): int
    {
        if (! config('constellation.enabled')) {
            $this->components->error('Constellation is disabled. Set CONSTELLATION_ENABLED=true to send snapshots.');

            return self::FAILURE;
        }

        if (blank(config('constellation.token'))) {
            $this->components->error('No Constellation token. Set CONSTELLATION_TOKEN in your environment.');

            return self::FAILURE;
        }

        $snapshot = $builder->build(readOnly: (bool) $this->option('dry-run'));

        if ($this->option('dry-run')) {
            $this->line($this->option('pretty') ? $snapshot->toPrettyJson() : $snapshot->body);

            return self::SUCCESS;
        }

        $job = new SendSnapshot($snapshot->body, $snapshot->idempotencyKey, $snapshot->windowTo());

        if ($this->option('sync')) {
            return $this->sendNow($job, $bus, $events);
        }

        // The snapshot that reports a dead worker must not wait behind it: with
        // one worker on `default`, the send job queues exactly where the
        // stall is.
        $blocked = $this->blockedSendQueue($snapshot->payload);

        if ($blocked !== null) {
            $this->components->warn("The queue the snapshot goes through ({$blocked}) looks blocked: sending it synchronously.");

            return $this->sendNow($job, $bus, $events);
        }

        $bus->dispatch($job);

        $this->components->info(sprintf(
            'Snapshot queued (window up to %s, idempotency key %s).',
            $snapshot->windowTo(),
            $snapshot->idempotencyKey,
        ));

        return self::SUCCESS;
    }

    /**
     * The connection and queue the send job would go to, as the snapshot just
     * collected sees them: stalled, or holding a job older than
     * `queues.critical_age_seconds`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function blockedSendQueue(array $payload): ?string
    {
        $connection = config('constellation.queue.connection') ?: config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return null;
        }

        $queue = config('constellation.queue.queue');
        $activity = $this->laravel->make(QueueActivity::class);
        $target = $activity->canonical($connection, is_string($queue) ? $queue : null);
        $critical = (int) config('constellation.queues.critical_age_seconds', 900);

        $sections = $payload['sections'] ?? [];
        $rows = is_array($sections) ? ($sections['queues']['data']['queues'] ?? []) : [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ($row['connection'] ?? null) !== $connection
                || $activity->canonical($connection, (string) ($row['name'] ?? '')) !== $target) {
                continue;
            }

            $age = $row['oldest_pending_age_seconds'] ?? null;

            if (($row['stalled'] ?? null) === true || (is_int($age) && $age >= $critical)) {
                return $connection.':'.$target;
            }
        }

        return null;
    }

    private function sendNow(SendSnapshot $job, Bus $bus, Events $events): int
    {
        $outcome = null;

        $events->listen(SnapshotSent::class, function (SnapshotSent $event) use (&$outcome): void {
            $this->components->info(sprintf(
                'Snapshot accepted (202%s).',
                $event->replayed ? ', replayed' : ''
            ));
            $outcome = self::SUCCESS;
        });

        $events->listen(SnapshotRejected::class, function (SnapshotRejected $event) use (&$outcome): void {
            $this->components->error(sprintf(
                'Snapshot rejected with HTTP %d (%s).',
                $event->status,
                $event->error ?? 'no error code'
            ));
            $outcome = self::FAILURE;
        });

        $events->listen(SnapshotSendFailed::class, function (SnapshotSendFailed $event) use (&$outcome): void {
            $this->components->error('Snapshot send failed: '.$event->reason);
            $outcome = self::FAILURE;
        });

        $bus->dispatchSync($job);

        if ($outcome === null) {
            $this->components->warn('The send was released for a second attempt; run the queue worker to complete it.');

            return self::FAILURE;
        }

        return $outcome;
    }
}
