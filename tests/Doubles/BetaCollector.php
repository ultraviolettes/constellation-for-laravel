<?php

namespace Ultraviolettes\Constellation\Tests\Doubles;

use Carbon\CarbonImmutable;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

final class BetaCollector extends TestCollector
{
    public function key(): string
    {
        return 'beta';
    }

    protected function section(Window $window): Section
    {
        return Section::make($this->key(), Status::Warning, [], CarbonImmutable::now('UTC'));
    }
}
