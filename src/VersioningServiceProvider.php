<?php

declare(strict_types=1);

namespace Rushing\Versioning;

use Illuminate\Support\ServiceProvider;

/**
 * Package service provider for the record-agnostic linear versioning seam.
 *
 * Merges the package config and (in console) publishes it. The `VersionStore`
 * singleton binding lands in ticket 03 once the store exists — the scaffold
 * only has to boot and resolve cleanly.
 *
 * Tenant migration ownership (ticket 01, LOCKED): the polymorphic `versions`
 * table DDL is authored by the *consuming app* under its tenant migration path;
 * this package ships the models/contracts/store, never the migration.
 */
final class VersioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/versioning.php', 'versioning');

        // Ticket 03: bind VersionStore as a singleton here once it exists, e.g.
        // $this->app->singleton(VersionStore::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/versioning.php' => config_path('versioning.php'),
            ], 'versioning-config');
        }
    }
}
