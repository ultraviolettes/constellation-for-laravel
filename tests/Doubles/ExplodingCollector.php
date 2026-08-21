<?php

namespace Ultraviolettes\Constellation\Tests\Doubles;

use RuntimeException;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Window;

final class ExplodingCollector extends TestCollector
{
    public function key(): string
    {
        return 'exploding';
    }

    protected function section(Window $window): Section
    {
        throw new RuntimeException('the client database is on fire');
    }
}
