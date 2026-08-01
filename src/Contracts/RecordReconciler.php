<?php

namespace Rushing\Versioning\Contracts;

use Rushing\Versioning\MigrationOutcome;

/**
 * The reconcile-on-read / eager-drain PROTOCOL: the reusable read-path + drain seam
 * a versioned record opts into, expressed once so adding migration to a new record
 * type does not mean re-deriving the read path.
 *
 * A concrete reconciler layers version RESOLUTION (record type -> current version,
 * stored id -> old version) and the safety rails (downgrade is a non-event, a
 * cross-stem / missing target is not comparable, a read never runs an expensive
 * rung) on top of the {@see Migrator} port. The schema-ladder adapter is the
 * reference implementation; it keys versions on the schema `$id`.
 *
 * The contract's guarantees, in terms every consumer classifies identically:
 *   - a payload already at the target reconciles as {@see MigrationOutcome::current()} with
 *     no work and no write-back;
 *   - a payload upgradable by CHEAP rungs only is {@see MigrationOutcome::migrated()} inline;
 *   - a payload whose target declares an EXPENSIVE rung reads as
 *     {@see MigrationOutcome::pending()} WITHOUT running it (a read never blocks on a model
 *     call or heavy transform);
 *   - a payload no cheap rung can migrate is {@see MigrationOutcome::failed()} with the
 *     ORIGINAL preserved immutably;
 *   - {@see readAtVersion()} is a pure, non-mutating view;
 *   - {@see reconcileEager()} runs the FULL ladder to complete a deferred record.
 */
interface RecordReconciler
{
    /**
     * Decide what a READ of a record written under `$storedId` (of `$recordType`,
     * carrying `$payload`) should do — running only CHEAP rungs inline and deferring
     * expensive ones to `pending`.
     *
     * `$targetVersion`, when given, PINS the target to a specific version rather than
     * the implicit current; the safety rails are unchanged under a pin.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $storedId  the version the record was written under
     * @param  string  $recordType  the record identity (a Data class-string / tenant stem in the adapter)
     */
    public function reconcile(array $payload, ?string $storedId, string $recordType, ?int $targetVersion = null): MigrationOutcome;

    /**
     * The current version identifier a fresh write of `$recordType` should be stamped
     * with — the target reconciliation advances toward. Empty string when the record
     * type carries no resolvable current version.
     */
    public function currentId(string $recordType): string;

    /**
     * Read a stored payload AT a deliberately chosen version — a pure, NON-mutating
     * view of a prior shape. Validate the payload against `$version` and report
     * current / failed WITHOUT running the ladder and WITHOUT writing back.
     *
     * @param  array<string, mixed>  $payload
     */
    public function readAtVersion(array $payload, string $recordType, int $version): MigrationOutcome;

    /**
     * The EAGER drain entry: run the FULL ladder (cheap + custom-transform rungs)
     * to complete a `pending` record the read path deferred. `$allowLlm` arms the
     * host-bound model rung — appended only when the caller's gate is satisfied; the
     * rung still self-gates on the target's opt-in and rides the same acceptance
     * gate, so a non-conforming candidate is quarantined. With `$allowLlm` false the
     * drain is deterministic rungs only.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $storedId  the version the record was written under
     */
    public function reconcileEager(array $payload, ?string $storedId, string $recordType, bool $allowLlm = false, ?int $targetVersion = null): MigrationOutcome;
}
