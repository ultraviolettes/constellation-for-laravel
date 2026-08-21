<?php

namespace Ultraviolettes\Constellation\Collectors\Jobs;

use Carbon\CarbonImmutable;

/**
 * Groups failure rows by signature over the window. `first_occurred_at` and
 * `last_occurred_at` are the window's min/max: the server keeps the historical
 * minimum (ADR 001, failure_groups).
 */
final class FailureGrouper
{
    /** @var array<string, array<string, mixed>> */
    private array $groups = [];

    public function __construct(private readonly string $timezone) {}

    public function add(
        string $signature,
        string $exceptionClass,
        ?string $jobClass,
        ?string $queue,
        string $normalizedMessage,
        ?string $occurredAt,
    ): void {
        $moment = $occurredAt === null ? null : CarbonImmutable::parse($occurredAt, $this->timezone)->utc();

        if (! isset($this->groups[$signature])) {
            $this->groups[$signature] = [
                'signature' => $signature,
                'exception_class' => $exceptionClass,
                'job_class' => $jobClass,
                'queue' => $queue,
                'message' => $normalizedMessage,
                'occurrences' => 0,
                'first_occurred_at' => $moment,
                'last_occurred_at' => $moment,
            ];
        }

        $group = &$this->groups[$signature];
        $group['occurrences']++;

        if ($moment !== null) {
            $group['first_occurred_at'] = $group['first_occurred_at'] === null
                ? $moment
                : $group['first_occurred_at']->min($moment);

            $group['last_occurred_at'] = $group['last_occurred_at'] === null
                ? $moment
                : $group['last_occurred_at']->max($moment);
        }
    }

    /**
     * @return list<array<string, mixed>> Top groups by occurrences, most recent first on ties.
     */
    public function top(int $limit): array
    {
        $groups = array_values($this->groups);

        usort($groups, function (array $a, array $b): int {
            return $b['occurrences'] <=> $a['occurrences']
                ?: ($b['last_occurred_at']?->getTimestamp() ?? 0) <=> ($a['last_occurred_at']?->getTimestamp() ?? 0);
        });

        return array_map(
            static fn (array $group): array => [
                ...$group,
                'first_occurred_at' => $group['first_occurred_at']?->toIso8601ZuluString(),
                'last_occurred_at' => $group['last_occurred_at']?->toIso8601ZuluString(),
            ],
            array_slice($groups, 0, $limit)
        );
    }
}
