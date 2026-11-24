<?php

namespace Rushing\Versioning\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/**
 * A {@see FixtureRecord} whose snapshotted `data` field uses a CLASS cast (AsArrayObject) — a cast
 * whose resolved value is held in the model's classCastCache once read. This is the shape that
 * exposes the cached-cast reset bug: `setRawAttributes($x, true)` runs syncOriginal() (which reads
 * getAttributes() → mergeAttributesFromCachedCasts()) BEFORE it clears the cast caches, so the value
 * cached from the REFUSED write is merged over the baseline and copied into `original`. A reset that
 * syncs-then-clears therefore records the wrong baseline; one that clears-then-syncs does not. Shares
 * the table and snapshot shape; only the cast differs.
 */
class CachingCastFixtureRecord extends FixtureRecord
{
    protected $casts = [
        'data' => AsArrayObject::class,
    ];
}
