<?php

namespace Rushing\Versioning\Tests\Fixtures;

/**
 * A {@see Versionable} record whose restoreVersionSnapshot() SAVES its own content, rather than only
 * filling attributes for the store to persist. Real consumers do this (a record that reconciles or
 * re-derives on restore and commits that itself) — and it is exactly the shape that exposes a
 * non-atomic restore: the content save commits, then the store's separate HEAD-pin save can be
 * vetoed, leaving content restored while HEAD never moved. Shares {@see FixtureRecord}'s table and
 * snapshot shape; only the restore path differs.
 */
class SelfSavingFixtureRecord extends FixtureRecord
{
    /**
     * Apply a frozen snapshot back onto the live record AND persist it here — a self-saving restore.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->fill([
            'name' => $snapshot['name'],
            'data' => $snapshot['data'],
        ])->save();
    }
}
