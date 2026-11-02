<?php

namespace Ultraviolettes\Constellation\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Console\AboutCommand;
use Orchestra\Testbench\TestCase as Orchestra;
use Ultraviolettes\Constellation\ConstellationServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * `AboutCommand` keeps its sections in static state: every provider boot
     * adds one more resolver. Never let one test see another's.
     */
    protected function tearDown(): void
    {
        AboutCommand::flushState();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ConstellationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config) {
            $config->set('cache.default', 'array');
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        });
    }
}
