<?php

use Ultraviolettes\Constellation\Snapshot\CollectorRegistry;
use Ultraviolettes\Constellation\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * @param  list<class-string>  $classes
 */
function registryFor(array $classes): CollectorRegistry
{
    return new CollectorRegistry(app(), $classes);
}
