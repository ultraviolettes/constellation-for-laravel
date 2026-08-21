<?php

namespace Ultraviolettes\Constellation\Snapshot;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Ultraviolettes\Constellation\Contracts\Collector;

final class CollectorRegistry
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * @param  list<class-string<Collector>>  $classes
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $classes,
    ) {}

    /**
     * @return list<Collector> Config order. A duplicate or invalid key fails immediately.
     */
    public function all(): array
    {
        $collectors = [];
        $seen = [];

        foreach ($this->classes as $class) {
            $collector = $this->container->make($class);

            if (! $collector instanceof Collector) {
                throw new InvalidArgumentException(sprintf(
                    '[%s] is registered as a Constellation collector but does not implement %s.',
                    $class,
                    Collector::class
                ));
            }

            $key = $collector->key();

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Collector [%s] returned the invalid section key [%s]; it must match %s.',
                    $class,
                    $key,
                    self::KEY_PATTERN
                ));
            }

            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Section key [%s] is declared by both [%s] and [%s].',
                    $key,
                    $seen[$key],
                    $class
                ));
            }

            $seen[$key] = $class;
            $collectors[] = $collector;
        }

        return $collectors;
    }
}
