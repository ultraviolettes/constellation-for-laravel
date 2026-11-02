<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Carbon\CarbonImmutable;

final readonly class Window
{
    public const SOURCE_CURSOR = 'cursor';

    public const SOURCE_CURSOR_TRUNCATED = 'cursor_truncated';

    public const SOURCE_INTERVAL_FALLBACK = 'interval_fallback';

    /**
     * @param  list<string>  $degradations  Reasons in detection order, empty when the window is nominal.
     * @param  bool  $readOnly  A collection that must leave no trace (`monitor:send --dry-run`).
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $source = self::SOURCE_CURSOR,
        public array $degradations = [],
        public bool $readOnly = false,
    ) {}

    public function readOnly(): self
    {
        return new self($this->from, $this->to, $this->source, $this->degradations, true);
    }

    public function isDegraded(): bool
    {
        return $this->degradations !== [];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601ZuluString(),
            'to' => $this->to->toIso8601ZuluString(),
        ];
    }
}
