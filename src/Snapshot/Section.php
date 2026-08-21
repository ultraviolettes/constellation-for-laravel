<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Carbon\CarbonImmutable;

final readonly class Section
{
    /**
     * Width of `snapshot_sections.reason` on the ingest side (ADR 001).
     */
    public const REASON_MAX_LENGTH = 200;

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public string $key,
        public Status $status,
        public Quality $quality,
        public ?string $reason,
        public ?CarbonImmutable $collectedAt,
        public array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $key, Status $status, array $data, CarbonImmutable $collectedAt): self
    {
        return new self($key, $status, Quality::Full, null, $collectedAt, $data);
    }

    /**
     * No observation at all. `quality` is Degraded rather than Full: nothing was
     * read, so the section must never read as a complete observation (ADR 004 R6).
     */
    public static function unavailable(string $key, string $reason): self
    {
        return new self($key, Status::Unavailable, Quality::Degraded, self::truncate($reason), null, []);
    }

    /**
     * Degraded copy. Multiple reasons concatenate with '+' in detection order.
     */
    public function degraded(string $reason): self
    {
        $merged = $this->reason === null || $this->reason === ''
            ? $reason
            : $this->reason.'+'.$reason;

        return new self(
            $this->key,
            $this->status,
            Quality::Degraded,
            self::truncate($merged),
            $this->collectedAt,
            $this->data,
        );
    }

    /**
     * @return array{status: string, quality: string, reason: string|null, collected_at: string|null, data: array<string, mixed>|object}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'quality' => $this->quality->value,
            'reason' => $this->reason,
            'collected_at' => $this->collectedAt?->toIso8601ZuluString(),
            // An empty data must encode as {} and not [] : the ingest column is jsonb object.
            'data' => $this->data === [] ? (object) [] : $this->data,
        ];
    }

    private static function truncate(string $reason): string
    {
        return mb_substr($reason, 0, self::REASON_MAX_LENGTH);
    }
}
