<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Illuminate\Support\Str;
use JsonException;

final class Snapshot
{
    /**
     * Frozen at construction, `sent_at` included, and carried as is up to the
     * send. Re-evaluating anything would change `payload_digest` and turn the
     * second attempt into a 409 instead of a replay (S24).
     */
    public readonly string $body;

    /**
     * Generated before any I/O, so that both attempts carry the same key (S25, D15).
     */
    public readonly string $idempotencyKey;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function __construct(
        public readonly array $payload,
        public readonly Window $window,
    ) {
        $this->idempotencyKey = Str::uuid()->toString();
        $this->body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    public function windowTo(): string
    {
        return $this->window->to->toIso8601ZuluString();
    }

    /**
     * @throws JsonException
     */
    public function toPrettyJson(): string
    {
        return json_encode(
            $this->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT
        );
    }
}
