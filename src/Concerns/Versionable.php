<?php

namespace Rushing\Versioning\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;

/**
 * Sane defaults + relation sugar for the {@see \Rushing\Versioning\Contracts\Versionable} contract:
 * a consuming model only has to implement the two content methods ({@see toVersionSnapshot()} /
 * {@see restoreVersionSnapshot()}); the HEAD pin defaults to `head_version` and the store delegation
 * comes for free.
 *
 * The content methods stay abstract on purpose — what to freeze is the record's identity, not a
 * convention, so each model declares it (mirrors how the reference leaves `workflowType()` abstract).
 */
trait Versionable
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toVersionSnapshot(): array;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    abstract public function restoreVersionSnapshot(array $snapshot): void;

    public function versionPinAttribute(): string
    {
        return 'head_version';
    }

    /**
     * The raw version-history relation for this record (unordered — the store returns newest-first).
     *
     * @return MorphMany<Version, static>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(Version::class, 'versionable');
    }

    /**
     * The version this record's HEAD pin currently points at.
     */
    public function headVersion(): ?Version
    {
        return app(VersionStore::class)->head($this);
    }

    /**
     * Freeze the record's current content into a new version and advance HEAD to it.
     */
    public function snapshotVersion(?string $label = null): Version
    {
        return app(VersionStore::class)->snapshot($this, $label);
    }

    /**
     * Roll this record back to a version (by UUID id or readable slug), moving HEAD to it.
     */
    public function restoreVersion(string $ref): void
    {
        app(VersionStore::class)->restore($this, $ref);
    }
}
