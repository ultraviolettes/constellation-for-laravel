<?php

namespace Ultraviolettes\Constellation\Collectors;

use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

final class AppCollector implements Collector
{
    public function __construct(private readonly AppFacts $facts) {}

    public function key(): string
    {
        return 'app';
    }

    public function collect(Window $window): Section
    {
        // The snapshot reads the clock once and hands it over through the
        // window: re-reading it here would let `collected_at` and the root
        // `server_time` disagree by a second within one payload (§6).
        $now = $window->to;
        $facts = $this->facts->all();

        $data = $this->facts->identity($now) + [
            'pending_migrations' => $facts['pending_migrations'],
            'config_cached' => $facts['config_cached'],
            'routes_cached' => $facts['routes_cached'],
        ];

        $section = Section::make($this->key(), $this->status($facts), $data, $now);

        if ($facts['pending_migrations'] === null) {
            $section = $section->degraded('migration_state_unreadable');
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function status(array $facts): Status
    {
        $production = $this->facts->isProduction();

        // Debug mode on in production: one property, and the single most
        // valuable line of the whole maintenance report.
        if ($production && $facts['debug_mode'] === true) {
            return Status::Critical;
        }

        if (is_int($facts['pending_migrations']) && $facts['pending_migrations'] > 0) {
            return Status::Warning;
        }

        if ($production && ($facts['config_cached'] === false || $facts['routes_cached'] === false)) {
            return Status::Warning;
        }

        return Status::Ok;
    }
}
