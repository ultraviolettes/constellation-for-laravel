<?php

namespace Ultraviolettes\Constellation\Tests\Doubles;

use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Window;

abstract class TestCollector implements Collector
{
    /** @var list<string> Section keys collected so far, in call order. */
    public static array $calls = [];

    public static ?Window $window = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$window = null;
    }

    public function collect(Window $window): Section
    {
        self::$calls[] = $this->key();
        self::$window = $window;

        return $this->section($window);
    }

    abstract protected function section(Window $window): Section;
}
