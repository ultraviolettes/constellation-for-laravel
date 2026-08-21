<?php

namespace Ultraviolettes\Constellation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher as Events;
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

        $snapshot = $builder->build();

        if ($this->option('dry-run')) {
            $this->line($this->option('pretty') ? $snapshot->toPrettyJson() : $snapshot->body);

            return self::SUCCESS;
        }

        $job = new SendSnapshot($snapshot->body, $snapshot->idempotencyKey, $snapshot->windowTo());

        if ($this->option('sync')) {
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
