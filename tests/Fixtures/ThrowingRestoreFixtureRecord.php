<?php

namespace Rushing\Versioning\Tests\Fixtures;

use RuntimeException;

/**
 * A fixture whose {@see restoreVersionSnapshot()} MUTATES the instance (and commits its own content)
 * and THEN throws a non-{@see \Rushing\Versioning\Exceptions\VersionWriteRefused} error — the shape of
 * a listener or a restore hook that fails AFTER it has already changed the model. The store must put
 * the instance back and rethrow THIS exception unchanged, exactly as it does for a vetoed save.
 */
class ThrowingRestoreFixtureRecord extends FixtureRecord
{
    protected $table = 'fixture_records';

    /** The message the thrown error carries, so a test can assert the ORIGINAL exception surfaces. */
    public const BOOM = 'restore hook failed after mutating';

    /**
     * Apply the snapshot (self-saving, so content commits inside the store's transaction), then throw.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->fill([
            'name' => $snapshot['name'],
            'data' => $snapshot['data'],
        ])->save();

        throw new RuntimeException(self::BOOM);
    }
}
