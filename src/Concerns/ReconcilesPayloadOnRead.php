<?php

namespace Rushing\Versioning\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\MigrationOutcome;
use Rushing\Versioning\MigrationStatus;

/**
 * The reusable read-path + drain seam a versioned Eloquent record opts into — the
 * record-agnostic lift of the Composition-shaped read-migration concern.
 *
 * A record stores the version identifier its payload was written under
 * ({@see versionColumn()}, default `schema_id`) and its {@see MigrationStatus}
 * ({@see statusColumn()}, default `migration_status`). On READ, when that stored id
 * is older than the current version, this concern runs the CHEAP rungs of the bound
 * {@see RecordReconciler} and applies the {@see MigrationOutcome}:
 *
 *  - a cheap rung migrates + the candidate conforms → the upgraded payload is
 *    surfaced and WRITTEN BACK (payload + version advanced, status `current`) so the
 *    cost amortizes once;
 *  - the upgrade needs an EXPENSIVE rung → the record reads `pending` with the
 *    ORIGINAL payload, never running it (the async drain handles it). A read NEVER
 *    blocks on a model call;
 *  - the cheap ladder quarantines → status `failed`, the ORIGINAL payload preserved.
 *
 * On write, {@see bootReconcilesPayloadOnRead} stamps the current version + resets
 * status `current` (a write always validates against current).
 *
 * A consuming model supplies three hooks: the bound reconciler
 * ({@see payloadReconciler()}), the record identity ({@see resolveRecordType()},
 * null when the record carries no versioned schema), and the payload column
 * ({@see payloadColumn()}). The four migration OUTCOMES and every safety rail live in
 * the reconciler, not here — this trait only applies the decision to the row.
 */
trait ReconcilesPayloadOnRead
{
    /**
     * Guard against re-entrant write-back triggering the saving hooks recursively.
     */
    protected bool $migratingPayload = false;

    /**
     * When set, a read serves/validates the payload UNDER this pinned version
     * (read-at-version) instead of migrating forward. Static + scoped via
     * {@see withPinnedSchemaVersion()} so it covers the rows read inside a closure
     * without threading a parameter through every query.
     */
    protected static ?int $pinnedSchemaVersion = null;

    /**
     * Run `$read` with reads of this model PINNED to `$version`: each row read inside
     * the closure is served/validated under `$version` via the reconciler's
     * non-mutating read-at-version path (no ladder, no write-back). The pin is always
     * restored, even on exception. Returns the closure's result.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $read
     * @return TReturn
     */
    public static function withPinnedSchemaVersion(int $version, Closure $read): mixed
    {
        $previous = static::$pinnedSchemaVersion;
        static::$pinnedSchemaVersion = $version;

        try {
            return $read();
        } finally {
            static::$pinnedSchemaVersion = $previous;
        }
    }

    public static function bootReconcilesPayloadOnRead(): void
    {
        // Stamp the current version on write. A write always validates against the
        // current schema, so the row is `current`.
        static::saving(function (Model $model): void {
            /** @var static $model */
            if ($model->migratingPayload) {
                return;
            }
            $model->stampCurrentSchema();
        });

        // Migrate a lagging payload forward on read.
        static::retrieved(function (Model $model): void {
            /** @var static $model */
            $model->migratePayloadOnRead();
        });
    }

    /**
     * Set the version column to the current identifier for this row's record type and
     * reset status to `current`. A no-op for records whose type imposes no versioned
     * schema.
     */
    public function stampCurrentSchema(): void
    {
        $recordType = $this->resolveRecordType();
        if ($recordType === null) {
            return;
        }

        $this->setAttribute($this->versionColumn(), $this->payloadReconciler()->currentId($recordType));
        $this->setAttribute($this->statusColumn(), MigrationStatus::Current->value);
    }

    /**
     * Reconcile the just-read payload against the current version and apply the
     * decision — surfacing the upgraded/original payload in-memory and writing back
     * exactly once where it amortizes.
     */
    public function migratePayloadOnRead(): void
    {
        $recordType = $this->resolveRecordType();
        if ($recordType === null) {
            return;
        }

        $payload = (array) ($this->getAttribute($this->payloadColumn()) ?? []);
        $storedId = $this->getAttribute($this->versionColumn());

        // Pinned read-at-version: serve/validate the stored payload UNDER the pinned
        // version without the forward-migration ladder and without writing back. The
        // stored payload is left in place; only the in-memory status reflects whether
        // it conforms to the pinned shape.
        if (static::$pinnedSchemaVersion !== null) {
            $outcome = $this->payloadReconciler()->readAtVersion(
                $payload,
                $recordType,
                static::$pinnedSchemaVersion,
            );

            $this->setAttribute($this->statusColumn(), $outcome->status->value);
            $this->syncOriginal();

            return;
        }

        $outcome = $this->payloadReconciler()->reconcile(
            $payload,
            is_string($storedId) ? $storedId : null,
            $recordType,
        );

        if ($outcome->status === MigrationStatus::Current && ! $outcome->shouldWriteBack) {
            return; // Already current — nothing to apply.
        }

        // Surface the decided payload in-memory (upgraded candidate, or original).
        if ($outcome->payload !== null) {
            $this->setAttribute($this->payloadColumn(), $outcome->payload);
        }
        $this->setAttribute($this->statusColumn(), $outcome->status->value);
        if ($outcome->versionId !== null) {
            $this->setAttribute($this->versionColumn(), $outcome->versionId);
        }

        // Write back once where the cost amortizes, then sync the original so this
        // read does not appear dirty / re-trigger writes.
        if ($outcome->shouldWriteBack) {
            $this->writeBackMigration($outcome);
        }

        $this->syncOriginal();
    }

    /**
     * The EAGER drain: run the FULL ladder (cheap + custom-transform) against this
     * row's payload and persist the outcome. This is what the drain calls to complete
     * `pending` rows the read path deferred. Returns the resolved {@see MigrationStatus}.
     *
     * Unlike {@see migratePayloadOnRead()} (cheap rungs only), the reconciler here is
     * armed with its full ladder, so an expensive rung the read deferred is actually
     * run. On success the upgraded payload + advanced version + `current` are written
     * back; on quarantine the row is marked `failed` with the ORIGINAL preserved.
     * `$allowLlm` arms the host-bound model rung (the reconciler still self-gates on
     * the schema opt-in).
     */
    public function migratePayloadEager(bool $allowLlm = false): MigrationStatus
    {
        $recordType = $this->resolveRecordType();
        if ($recordType === null) {
            return MigrationStatus::Current;
        }

        $payload = (array) ($this->getAttribute($this->payloadColumn()) ?? []);
        $storedId = $this->getAttribute($this->versionColumn());

        $outcome = $this->payloadReconciler()->reconcileEager(
            $payload,
            is_string($storedId) ? $storedId : null,
            $recordType,
            $allowLlm,
        );

        if ($outcome->status === MigrationStatus::Current && ! $outcome->shouldWriteBack) {
            return MigrationStatus::Current;
        }

        if ($outcome->payload !== null) {
            $this->setAttribute($this->payloadColumn(), $outcome->payload);
        }
        $this->setAttribute($this->statusColumn(), $outcome->status->value);
        if ($outcome->versionId !== null) {
            $this->setAttribute($this->versionColumn(), $outcome->versionId);
        }

        $this->writeBackMigration($outcome);
        $this->syncOriginal();

        return $outcome->status;
    }

    /**
     * Persist the migration outcome once. For a cheap success this writes the upgraded
     * payload + advanced version + `current`; for pending/failed it writes ONLY the
     * status (and original/lagging version) — the original payload is never
     * overwritten.
     */
    protected function writeBackMigration(MigrationOutcome $outcome): void
    {
        $this->migratingPayload = true;

        try {
            $updates = [$this->statusColumn() => $outcome->status->value];

            if ($outcome->status === MigrationStatus::Current && $outcome->payload !== null) {
                $updates[$this->payloadColumn()] = $outcome->payload;
                if ($outcome->versionId !== null) {
                    $updates[$this->versionColumn()] = $outcome->versionId;
                }
            }

            // A direct UPDATE bypasses the saving/validating hooks: a cheap-rung
            // candidate already passed the acceptance gate, and a pending/failed write
            // must not re-validate the lagging payload against current.
            static::withoutEvents(function () use ($updates) {
                static::query()->whereKey($this->getKey())->update($updates);
            });
        } finally {
            $this->migratingPayload = false;
        }
    }

    /** The column holding the row's version identifier (its absolute schema `$id`). */
    public function versionColumn(): string
    {
        return 'schema_id';
    }

    /** The column holding the row's {@see MigrationStatus}. */
    public function statusColumn(): string
    {
        return 'migration_status';
    }

    /**
     * The bound migrator this record reconciles through.
     */
    abstract protected function payloadReconciler(): RecordReconciler;

    /**
     * The record identity the reconciler resolves versions from (a Data class-string
     * / tenant stem in the schema-ladder adapter), or null when this record carries no
     * versioned schema — in which case the whole concern is a no-op for the row.
     */
    abstract protected function resolveRecordType(): ?string;

    /** The JSON column on this model holding the typed payload. */
    abstract public function payloadColumn(): string;
}
