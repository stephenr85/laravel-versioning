<?php

declare(strict_types=1);

namespace Rushing\Versioning\Contracts;

/**
 * The seam where the two versioning disciplines compose: a {@see Versionable} record that ALSO
 * migrates-across-versions can opt into running an OLDER snapshot forward through its bound
 * {@see Migrator} before the snapshot is rehydrated.
 *
 * A frozen snapshot holds payloads shaped to the schema version current WHEN it was frozen. Restoring
 * it verbatim would rehydrate stale-shaped payloads. A record implementing this contract upcasts them
 * first, so a restore of an old version lands a CURRENT-shaped record. The store honours it in
 * {@see VersionStore::restore()}, immediately before {@see Versionable::restoreVersionSnapshot()}:
 *
 *     $snapshot = $version->snapshot;
 *     if ($model instanceof MigratesSnapshotOnRestore) {
 *         $snapshot = $model->migrateSnapshotForward($snapshot);
 *     }
 *     $model->restoreVersionSnapshot($snapshot);
 *
 * It is OPT-IN, mirroring the store's other "layered on top, not baked in" decision (whether a restore
 * also mints a fresh version): a Versionable record that does not implement this simply restores its
 * snapshot verbatim. The migration is the record's own concern — the store never inspects snapshot
 * shape — so the record walks its own payloads and runs each through its bound `Migrator`.
 */
interface MigratesSnapshotOnRestore
{
    /**
     * Return the snapshot with each embedded, versioned payload run FORWARD through its bound
     * {@see Migrator} toward the current version. A payload no cheap rung can migrate (or one needing a
     * deferred expensive rung) is left as its preserved original — the record's read path handles it.
     * The shape is otherwise unchanged; only stale payloads are upcast.
     *
     * @param  array<string, mixed>  $snapshot  the frozen snapshot ({@see Versionable::toVersionSnapshot()})
     * @return array<string, mixed> the same snapshot with stale payloads migrated forward
     */
    public function migrateSnapshotForward(array $snapshot): array;
}
