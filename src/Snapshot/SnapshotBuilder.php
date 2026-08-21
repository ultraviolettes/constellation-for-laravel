<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Ultraviolettes\Constellation\Collectors\AppFacts;
use Ultraviolettes\Constellation\Constellation;
use Ultraviolettes\Constellation\Events\SnapshotBuilt;
use Ultraviolettes\Constellation\Exceptions\CollectorUnavailable;

final class SnapshotBuilder
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly WindowResolver $windows,
        private readonly AppFacts $facts,
        private readonly Dispatcher $events,
    ) {}

    public function build(): Snapshot
    {
        $now = CarbonImmutable::now('UTC');
        $window = $this->windows->resolve($now);

        $sections = [];

        foreach ($this->registry->all() as $collector) {
            $key = $collector->key();
            $sections[$key] = $this->safely($key, fn (): Section => $collector->collect($window))->toArray();
        }

        $snapshot = new Snapshot([
            'schema' => Constellation::SCHEMA,
            'sent_at' => $now->toIso8601ZuluString(),
            'window' => $window->toArray(),
            'app' => $this->facts->identity($now),
            'sections' => $sections === [] ? (object) [] : $sections,
        ], $window);

        $this->events->dispatch(new SnapshotBuilt($snapshot));

        return $snapshot;
    }

    /**
     * A collector that blows up never prevents the other sections from being sent.
     *
     * @param  callable(): Section  $collect
     */
    private function safely(string $key, callable $collect): Section
    {
        try {
            return $collect();
        } catch (CollectorUnavailable $exception) {
            return Section::unavailable($key, $exception->reason());
        } catch (Throwable $exception) {
            report($exception);

            return Section::unavailable($key, 'collector_exception');
        }
    }
}
