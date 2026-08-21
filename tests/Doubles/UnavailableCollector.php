<?php

namespace Ultraviolettes\Constellation\Tests\Doubles;

use Ultraviolettes\Constellation\Exceptions\CollectorUnavailable;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Window;

final class UnavailableCollector extends TestCollector
{
    public function key(): string
    {
        return 'unreachable';
    }

    protected function section(Window $window): Section
    {
        throw new CollectorUnavailable('queue_monitor_table_missing');
    }
}
