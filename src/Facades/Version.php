<?php

namespace Rushing\Versioning\Facades;

use Illuminate\Support\Facades\Facade;
use Rushing\Versioning\Contracts\VersionStore;

/**
 * Ergonomic front door to the versioned store — the read/write door a host app
 * calls to freeze, list, resolve, restore, and pin record versions.
 *
 * @method static \Rushing\Versioning\Models\Version snapshot(\Rushing\Versioning\Contracts\Versionable $model, ?string $label = null)
 * @method static \Illuminate\Support\Collection versions(\Rushing\Versioning\Contracts\Versionable $model)
 * @method static ?\Rushing\Versioning\Models\Version version(\Rushing\Versioning\Contracts\Versionable $model, string $ref)
 * @method static void restore(\Rushing\Versioning\Contracts\Versionable $model, string $ref)
 * @method static ?\Rushing\Versioning\Models\Version head(\Rushing\Versioning\Contracts\Versionable $model)
 *
 * @see VersionStore
 */
class Version extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VersionStore::class;
    }
}
