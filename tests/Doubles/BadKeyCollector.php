<?php

namespace Ultraviolettes\Constellation\Tests\Doubles;

use Carbon\CarbonImmutable;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Status;
use Ultraviolettes\Constellation\Snapshot\Window;

final class BadKeyCollector implements Collector
{
    public static string $key = 'Not-A-Valid-Key';

    public function key(): string
    {
        return self::$key;
    }

    public function collect(Window $window): Section
    {
        return Section::make($this->key(), Status::Ok, [], CarbonImmutable::now('UTC'));
    }
}
