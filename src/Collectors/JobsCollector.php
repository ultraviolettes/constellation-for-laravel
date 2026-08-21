<?php

namespace Ultraviolettes\Constellation\Collectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Ultraviolettes\Constellation\Collectors\Jobs\FailedJobsSource;
use Ultraviolettes\Constellation\Collectors\Jobs\QueueMonitorSource;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

final class JobsCollector implements Collector
{
    public const REASON_QUEUE_MONITOR_ABSENT = 'queue_monitor_absent';

    public const REASON_ROW_LIMIT = 'read_limit_reached';

    public const REASON_NO_SOURCE = 'no_failure_source';

    public function __construct(
        private readonly QueueMonitorSource $queueMonitors,
        private readonly FailedJobsSource $failedJobs,
        private readonly Config $config,
    ) {}

    public function key(): string
    {
        return 'jobs';
    }

    public function collect(Window $window): Section
    {
        $now = CarbonImmutable::now('UTC');
        $modeA = $this->queueMonitors->isAvailable();

        if (! $modeA && ! $this->failedJobs->isAvailable()) {
            return Section::unavailable($this->key(), self::REASON_NO_SOURCE);
        }

        $reading = $modeA
            ? $this->queueMonitors->read($window)
            : $this->failedJobs->read($window);

        $processed = $reading['processed'];
        $failed = $reading['failed'];

        $section = Section::make($this->key(), $this->status($failed), [
            'window_source' => $window->source,
            'source' => $modeA ? QueueMonitorSource::NAME : FailedJobsSource::NAME,
            'totals' => [
                'processed' => $processed,
                'failed' => $failed,
                'success_rate' => $processed === null || $processed === 0
                    ? null
                    : round(($processed - $failed) / $processed, 5),
            ],
            'failure_groups' => $reading['groups'],
        ], $now);

        // Detection order matters: the reason string concatenates with '+'.
        foreach ($window->degradations as $reason) {
            $section = $section->degraded($reason);
        }

        if (! $modeA) {
            $section = $section->degraded(self::REASON_QUEUE_MONITOR_ABSENT);
        }

        if ($reading['truncated']) {
            $section = $section->degraded(self::REASON_ROW_LIMIT);
        }

        return $section;
    }

    private function status(int $failed): Status
    {
        $critical = (int) $this->config->get('constellation.jobs.critical_failures', 25);
        $warning = (int) $this->config->get('constellation.jobs.warning_failures', 1);

        if ($failed >= $critical) {
            return Status::Critical;
        }

        if ($failed >= $warning) {
            return Status::Warning;
        }

        return Status::Ok;
    }
}
