<?php

namespace Rushing\Versioning;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Rushing\Versioning\Contracts\ReadableVersionResolver;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Facades\Version;
use Rushing\Versioning\Readable\SequentialReadableVersion;
use Rushing\Versioning\Readable\ShortUuidReadableVersion;
use Rushing\Versioning\Store\EloquentVersionStore;

/**
 * Package service provider for the record-agnostic linear versioning seam.
 *
 * Binds the two singletons the seam resolves through — the {@see VersionStore} (freeze / list /
 * resolve / restore / head) and the {@see ReadableVersionResolver} (how a version's integer renders
 * to humans, config-selected) — and merges/publishes the package config.
 *
 * Tenant migration ownership (ticket 01, LOCKED): the polymorphic `versions` table DDL is authored
 * by the *consuming app* under its tenant migration path; this package ships the models/contracts/
 * store, never the migration — so it stays consumable by any Laravel app, tenant or not.
 */
class VersioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/versioning.php', 'versioning');

        $this->app->singleton(VersionStore::class, EloquentVersionStore::class);

        $this->app->singleton(ReadableVersionResolver::class, static function (Application $app): ReadableVersionResolver {
            $strategy = $app['config']->get('versioning.readable_version', 'sequential');

            return match ($strategy) {
                'short-uuid', 'short_uuid' => new ShortUuidReadableVersion,
                default => new SequentialReadableVersion,
            };
        });
    }

    public function boot(): void
    {
        if (class_exists(AliasLoader::class)) {
            AliasLoader::getInstance()->alias('Version', Version::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/versioning.php' => config_path('versioning.php'),
            ], 'versioning-config');
        }
    }
}
