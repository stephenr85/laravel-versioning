<?php

declare(strict_types=1);

namespace Rushing\Versioning\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Versioning\Tests\Fixtures\FixtureRecord;
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

    /**
     * Build the schema the conformance suite runs against. The package ships ZERO migration by design
     * (the host app owns the tenant DDL — contract §3), so the test harness stands up its own copy of
     * the `versions` table exactly per the locked contract shape, plus a `fixture_records` table with
     * a `head_version` pin for {@see FixtureRecord}. This mirrors
     * the app-authored `2026_07_21_000004_create_versions_table` migration.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['versionable_type', 'versionable_id', 'version']);
            $table->index(['versionable_type', 'versionable_id']);
        });

        Schema::create('fixture_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('data')->nullable();
            $table->uuid('head_version')->nullable();
            $table->timestamps();
        });
    }
}
