<?php

declare(strict_types=1);

namespace Rushing\Versioning\Contracts;

use Illuminate\Support\Collection;
use Rushing\Versioning\Models\Version;
use Rushing\Versioning\VersioningServiceProvider;

/**
 * The read/write door onto the versioned store — the five methods that survive dropping the
 * reference's lineage machinery (splicewire/laravel-beam-workflows' DefinitionStore). There is no
 * lineage here: the record IS its own lineage (a polymorphic morph, not a shared string key), so
 * `createLineage`/`fork`/`activateVersion`/`ensureSystemLineage` are N/A and gone.
 *
 * Bound as a singleton by {@see VersioningServiceProvider}.
 */
interface VersionStore
{
    /**
     * Freeze the record's current content ({@see Versionable::toVersionSnapshot()}) into a new
     * immutable version — the next per-record monotonic `version` — and advance HEAD to it.
     */
    public function snapshot(Versionable $model, ?string $label = null): Version;

    /**
     * The record's full version history, newest-first.
     *
     * @return Collection<int, Version>
     */
    public function versions(Versionable $model): Collection;

    /**
     * Resolve one version of this record by its UUID id OR its readable slug (see
     * {@see ReadableVersionResolver}). Null if no such version belongs to the record.
     */
    public function version(Versionable $model, string $ref): ?Version;

    /**
     * Apply a version's snapshot back onto the record ({@see Versionable::restoreVersionSnapshot()})
     * and move HEAD to it. This is the pointer-move primitive; whether a restore ALSO mints a fresh
     * version (append-only history) is a consumer decision layered on top, not baked in here.
     */
    public function restore(Versionable $model, string $ref): void;

    /**
     * The version the record's HEAD pin currently points at, or null if unpinned.
     */
    public function head(Versionable $model): ?Version;
}
