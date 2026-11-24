<?php

namespace Rushing\Versioning\Contracts;

use Illuminate\Support\Collection;
use Rushing\Versioning\Exceptions\VersionWriteRefused;
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
     *
     * The pre-call reset after a refused (or otherwise aborted) write is BEST-EFFORT and covers
     * ATTRIBUTE state only (attributes + the `original` baseline, cached casts included); it does NOT
     * restore the post-save change log, loaded relations, or custom in-memory state. If the reset
     * ITSELF throws — a record that overrides `setRawAttributes()`, or carries a cast that can throw on
     * merge — that error is swallowed so the original refusal surfaces unmasked, and the instance's
     * attribute state is then UNSPECIFIED: such a record must be DISCARDED / re-fetched, not reused.
     *
     * @throws VersionWriteRefused when a halting `saving`/`updating` listener vetoes the HEAD-pin
     *                             write: the transaction rolls the minted version back and nothing
     *                             persists, and the record instance's attribute state (and its dirty
     *                             set) is left exactly as it was pre-call — the attempted pin discarded.
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
     *
     * Content-apply and pin-move run in one transaction, so a vetoed restore rolls BOTH back even when
     * the record's restoreVersionSnapshot() saves its own content; the record instance's attribute
     * state (and its dirty set) is left exactly as it was pre-call.
     *
     * The pre-call reset is BEST-EFFORT, exactly as described on {@see self::snapshot()}: attribute
     * state only, and UNSPECIFIED if the reset itself throws (an overridden `setRawAttributes()` or a
     * cast that throws on merge) — such a record must be DISCARDED / re-fetched, not reused.
     *
     * @throws VersionWriteRefused when a halting `saving`/`updating` listener vetoes the restoring
     *                             write: nothing persists and the record instance's attribute state is
     *                             left exactly as it was pre-call (the caller's own pre-call edits
     *                             preserved; the attempted restore discarded). Any other throwable
     *                             raised mid-restore is rethrown unchanged after the same reset.
     */
    public function restore(Versionable $model, string $ref): void;

    /**
     * The version the record's HEAD pin currently points at, or null if unpinned.
     */
    public function head(Versionable $model): ?Version;
}
