<?php

namespace Ultraviolettes\Constellation\Contracts;

use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Window;

interface Collector
{
    /**
     * Section key, matching /^[a-z][a-z0-9_]{0,39}$/.
     */
    public function key(): string;

    public function collect(Window $window): Section;
}
