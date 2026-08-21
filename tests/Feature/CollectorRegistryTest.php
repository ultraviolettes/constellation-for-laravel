<?php

use Ultraviolettes\Constellation\Collectors\AppCollector;
use Ultraviolettes\Constellation\Contracts\Collector;
use Ultraviolettes\Constellation\Snapshot\Section;
use Ultraviolettes\Constellation\Snapshot\Window;

it('rejects a duplicate section key', function () {
    $registry = registryFor([AppCollector::class, AppCollector::class]);

    expect(fn () => $registry->all())
        ->toThrow(InvalidArgumentException::class, 'Section key [app] is declared by both');
});

it('rejects a section key the ingest endpoint would silently ignore', function () {
    $registry = registryFor([BadKeyCollector::class]);

    expect(fn () => $registry->all())->toThrow(InvalidArgumentException::class);
});

final class BadKeyCollector implements Collector
{
    public function key(): string
    {
        return 'Not-A-Valid-Key';
    }

    public function collect(Window $window): Section
    {
        return Section::unavailable($this->key(), 'never');
    }
}
