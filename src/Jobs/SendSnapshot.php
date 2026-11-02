<?php

namespace Ultraviolettes\Constellation\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;
use Ultraviolettes\Constellation\Events\SnapshotRejected;
use Ultraviolettes\Constellation\Events\SnapshotSendFailed;
use Ultraviolettes\Constellation\Events\SnapshotSent;
use Ultraviolettes\Constellation\Http\SnapshotClient;
use Ultraviolettes\Constellation\Snapshot\LastDelivery;
use Ultraviolettes\Constellation\Snapshot\WindowResolver;

/**
 * Carries three strings and no object — above all, no token (S26): a job payload
 * is written to `jobs`, to `failed_jobs` and to Horizon in clear text.
 */
final class SendSnapshot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Codes that will never accept this body: a second attempt would only fill
     * the server's ingest-auth-failures counter (S28).
     */
    public const DEFINITIVE = [400, 401, 402, 409, 413, 415, 422];

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct(
        public string $body,
        public string $idempotencyKey,
        public string $windowTo,
    ) {
        $this->onConnection(config('constellation.queue.connection'));
        $this->onQueue(config('constellation.queue.queue'));
    }

    public function handle(SnapshotClient $client, WindowResolver $windows, Dispatcher $events, LastDelivery $delivery): void
    {
        try {
            $response = $client->send($this->body, $this->idempotencyKey);
        } catch (ConnectionException $exception) {
            $this->retryOrAbandon($events, 'connection_error: '.$exception->getMessage());

            return;
        }

        $status = $response->status();

        if ($status === 202) {
            $sentAt = CarbonImmutable::now('UTC');

            $windows->advance(CarbonImmutable::parse($this->windowTo), $sentAt);
            $delivery->record($this->body, $this->windowTo, $sentAt);

            $events->dispatch(new SnapshotSent(
                $this->idempotencyKey,
                $this->windowTo,
                $response->header('Idempotency-Replayed') === 'true',
            ));

            return;
        }

        if (in_array($status, self::DEFINITIVE, true)) {
            $this->reject($events, $response, $status);

            return;
        }

        $this->retryOrAbandon(
            $events,
            'http_'.$status,
            $status === 429 ? $this->retryAfter($response) : null,
        );
    }

    /**
     * Safety net for anything the queue worker itself throws (timeout, worker
     * kill). The nominal abandon path never reaches here.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Constellation snapshot abandoned.', [
            'idempotency_key' => $this->idempotencyKey,
            'window_to' => $this->windowTo,
            'reason' => $exception?->getMessage() ?? 'unknown',
        ]);

        event(new SnapshotSendFailed($this->idempotencyKey, 'job_failed', $this->attempts()));
    }

    private function reject(Dispatcher $events, Response $response, int $status): void
    {
        $error = is_string($response->json('error')) ? $response->json('error') : null;

        $context = [
            'idempotency_key' => $this->idempotencyKey,
            'window_to' => $this->windowTo,
            'status' => $status,
            'error' => $error,
        ];

        // A 409 cannot happen: the body is frozen at construction, so both
        // attempts carry the same digest. If it does, it is an SDK bug.
        $status === 409
            ? Log::error('Constellation rejected the snapshot with an idempotency conflict.', $context)
            : Log::warning('Constellation rejected the snapshot.', $context);

        $events->dispatch(new SnapshotRejected($this->idempotencyKey, $status, $error));
    }

    /**
     * Abandon is a warning in the log, never an uncaught exception and never a
     * row in the client's `failed_jobs` — which our own jobs collector reads.
     */
    private function retryOrAbandon(Dispatcher $events, string $reason, ?int $delay = null): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($delay ?? $this->backoff);

            return;
        }

        Log::warning('Constellation snapshot abandoned after the last attempt.', [
            'idempotency_key' => $this->idempotencyKey,
            'window_to' => $this->windowTo,
            'reason' => $reason,
            'attempts' => $this->attempts(),
        ]);

        $events->dispatch(new SnapshotSendFailed($this->idempotencyKey, $reason, $this->attempts()));
    }

    private function retryAfter(Response $response): int
    {
        $header = (int) $response->header('Retry-After');

        return $header > 0 ? min($header, 60) : $this->backoff;
    }
}
