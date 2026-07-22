<?php

declare(strict_types=1);

namespace Rushing\Versioning\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Versioning\VersioningServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [VersioningServiceProvider::class];
    }

    /**
     * The Version model reads through illuminate/database, so DB-backed
     * conformance tests (ticket 04) need a connection. An in-memory sqlite
     * default keeps the suite self-contained.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
