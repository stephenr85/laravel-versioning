<?php

namespace Rushing\Versioning\Store;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Rushing\Versioning\Contracts\MigratesSnapshotOnRestore;
use Rushing\Versioning\Contracts\Versionable;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Exceptions\VersionWriteRefused;
use Rushing\Versioning\Models\Version;

/**
 * The Eloquent {@see VersionStore} adapter. Everything that freezes, lists, resolves, or restores a
 * version goes through here, so the monotonic-allocation + HEAD-pin discipline lives in exactly one
 * place. The record is its own lineage: rows are scoped by the polymorphic morph
 * (`versionable_type`/`versionable_id`), never a lineage key.
 *
 * Snapshot serialization is the record's job ({@see Versionable::toVersionSnapshot()}); this store
 * never inspects snapshot shape — that is what keeps it consumable by any schema-driven record.
 *
 * The {@see VersionStore} contract stays storage-agnostic — it types the record as {@see Versionable}
 * (the three-method socket), so an alternate backend could implement it. THIS adapter is Eloquent: it
 * mints an Eloquent {@see Version}, moves a HEAD pin, and scopes by morph — all of which need the
 * `Model` API beyond the socket. Rather than leak `Model` into the port, the Eloquent requirement is
 * enforced HERE, at the adapter boundary, by {@see eloquent()} — which narrows a {@see Versionable} to
 * the `Model&Versionable` intersection (a hard error if a non-Eloquent record is ever passed).
 *
 * @see VersionStore for the surface contract.
 */
class EloquentVersionStore implements VersionStore
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private AuthFactory $auth,
    ) {}

    public function snapshot(Versionable $model, ?string $label = null): Version
    {
        // `next = max(version) + 1` inside the insert's transaction; the unique
        // (versionable_type, versionable_id, version) index is the correctness backstop — a colliding
        // concurrent snapshot fails loudly and we retry exactly once. No row lock in v1: snapshots are
        // explicit, per-record acts, so contention is effectively zero.
        return $this->allocate($this->eloquent($model), $label, retriesLeft: 1);
    }

    public function versions(Versionable $model): Collection
    {
        return $this->scoped($this->eloquent($model))->orderByDesc('version')->get();
    }

    public function version(Versionable $model, string $ref): ?Version
    {
        $model = $this->eloquent($model);

        // A uuid ref is a primary-key lookup; anything else is a readable slug. Gate the key lookup on
        // uuid shape so a slug ref never reaches the DB as a malformed uuid — Postgres rejects a
        // non-uuid string against a `uuid` column with a 22P02 cast error rather than returning no
        // rows (sqlite's lax text compare hid this in the conformance suite; the real tenant DB is PG).
        if (Str::isUuid($ref)) {
            $byId = $this->scoped($model)->whereKey($ref)->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        // Fall back to the readable slug — resolved back through the same resolver the model renders.
        return $this->versions($model)->first(
            static fn (Version $version): bool => $version->readableVersion() === $ref,
        );
    }

    public function restore(Versionable $model, string $ref): void
    {
        $model = $this->eloquent($model);

        $version = $this->version($model, $ref);

        if ($version === null) {
            throw new InvalidArgumentException(
                "No version [{$ref}] belongs to this record; cannot restore.",
            );
        }

        // Compose with the migration seam: a record that migrates-across-versions runs an OLDER
        // snapshot forward through its bound Migrator before rehydration, so a restore lands a
        // CURRENT-shaped record. Opt-in and the record's own concern (the store never inspects
        // snapshot shape); a plain Versionable restores verbatim.
        $snapshot = $version->snapshot;
        if ($model instanceof MigratesSnapshotOnRestore) {
            $snapshot = $model->migrateSnapshotForward($snapshot);
        }

        // Restoring must be atomic. A record whose restoreVersionSnapshot() saves its OWN content (a
        // self-saving impl) would otherwise COMMIT that content before the separate pin-move; if the
        // pin-move is then vetoed, the content stands restored while HEAD never moved — a split write.
        // Apply the content, move the pin, and persist inside ONE transaction on the record's own
        // connection (as allocate() does), so a refusal rolls BOTH back. Capture BOTH the pre-restore
        // attributes AND the DB-synced original baseline (before any mutation), so the instance reset
        // below restores the EXACT pre-call dirty set — a caller's own unsaved edit survives.
        $attributes = $model->getAttributes();
        $original = $model->getRawOriginal();

        try {
            $this->db->connection($model->getConnectionName())->transaction(function () use ($model, $snapshot, $version): void {
                // The pointer-move primitive; a consumer that wants restore to ALSO mint a fresh version
                // (append-only history) layers a snapshot() call on top — the store does not bake that in.
                $model->restoreVersionSnapshot($snapshot);
                $model->setAttribute($model->versionPinAttribute(), $version->id);

                // The pointer-move can be refused: a halting `saving`/`updating` listener makes `save()`
                // return false without persisting. Throw so the transaction rolls back any content a
                // self-saving restoreVersionSnapshot() already committed — nothing persists.
                if (! $model->save()) {
                    throw VersionWriteRefused::restore($model);
                }
            });
        } catch (\Throwable $e) {
            // The transaction rolled the ROW back, but the in-memory instance still carries the refused
            // snapshot + pin — and so it would after ANY throwable that aborted mid-restore (a veto, or
            // a listener that threw AFTER mutating the model), so a later save()/saveQuietly() on this
            // same instance would store that write. Put the instance back to its pre-restore state and
            // rethrow the ORIGINAL exception unchanged. (Synchronous `saved` observer side effects that
            // already ran are NOT undone by the rollback — only the row and these attributes are.)
            $this->discardRefusedWrite($model, $attributes, $original);

            throw $e;
        }
    }

    public function head(Versionable $model): ?Version
    {
        $model = $this->eloquent($model);

        $pin = $model->getAttribute($model->versionPinAttribute());

        if (blank($pin)) {
            return null;
        }

        return $this->scoped($model)->whereKey($pin)->first();
    }

    /**
     * Freeze one version transactionally, retrying once on a unique collision (a concurrent snapshot
     * that grabbed the same `version` number).
     */
    private function allocate(Model&Versionable $model, ?string $label, int $retriesLeft): Version
    {
        // Pre-snapshot attributes AND the DB-synced original baseline, for the instance reset if the
        // pin-move is refused below — both, so the caller's pre-call dirty set is preserved exactly.
        $attributes = $model->getAttributes();
        $original = $model->getRawOriginal();

        try {
            // The record's own connection, for the transaction and the row (see {@see on()}).
            return $this->db->connection($model->getConnectionName())->transaction(function () use ($model, $label): Version {
                $version = $this->on($model)->create([
                    'versionable_type' => $model->getMorphClass(),
                    'versionable_id' => $model->getKey(),
                    'version' => $this->nextVersionNumber($model),
                    'snapshot' => $model->toVersionSnapshot(),
                    'label' => $label,
                    'created_by' => $this->auth->guard()->id(),
                ]);

                // Advance the HEAD pin on the record to the version just minted.
                $model->setAttribute($model->versionPinAttribute(), $version->id);

                // The pin-move can be refused: a halting `saving`/`updating` listener makes `save()`
                // return false without persisting. A swallowed boolean would leave a freshly minted
                // version with nothing pointing at it. Throw so the enclosing transaction rolls the
                // version back — nothing persists.
                if (! $model->save()) {
                    throw VersionWriteRefused::snapshot($model);
                }

                return $version;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A unique collision raised by the PIN SAVE (not the version insert) mutated the instance
            // with the attempted pin BEFORE it threw. Reset to THIS frame's true pre-call pair FIRST —
            // before BOTH the retry and the final rethrow — so every attempt starts from the real
            // pre-call state. Otherwise the recursive retry re-captures the polluted instance (the
            // failed pin becomes its "pre-call" baseline) and the exhausted reset would put that failed
            // pin BACK. (A version-insert collision leaves the instance unmutated, so this is a no-op.)
            $wasReset = $this->discardRefusedWrite($model, $attributes, $original);

            if ($wasReset && $retriesLeft > 0) {
                // Retry once; the re-entrant allocate() now captures the reset (true pre-call) state.
                // Only when the reset actually succeeded — a reset that threw leaves the instance
                // carrying the refused pin, so retrying would re-capture that polluted pin as the new
                // "pre-call" baseline. Surface the original collision at once instead.
                return $this->allocate($model, $label, $retriesLeft - 1);
            }

            throw $e;
        } catch (\Throwable $e) {
            // The transaction rolled back the freshly minted version row, but the in-memory instance
            // still carries the refused HEAD pin — as it would after ANY throwable that aborted the
            // mint mid-flight; a later save() on this same instance would store it. Put the instance
            // back to its pre-snapshot state and rethrow the ORIGINAL exception unchanged. (A unique
            // collision is handled by the retry catch above and never reaches here.)
            $this->discardRefusedWrite($model, $attributes, $original);

            throw $e;
        }
    }

    /**
     * Put a record's in-memory state back to what it held before a refused (or otherwise aborted)
     * write, so the rolled-back row and the live instance agree. A refusal rolls the ROW back, but the
     * instance keeps the refused snapshot/pin it was given; a later save()/saveQuietly() on that same
     * instance would then store the write the veto refused.
     *
     * Restores BOTH halves of the dirty comparison — the attributes AND the DB-synced `original`
     * baseline — to their pre-call values, so the caller's OWN pre-call dirty set is preserved EXACTLY:
     * only the store's attempted pin/restore mutation is discarded. A blanket syncOriginal() would
     * instead mark a caller's unsaved pre-call edit "clean", so a later save() would silently drop it.
     * Three setRawAttributes()/syncOriginal() steps do this with no reflection, and crucially CLEAR the
     * cast caches BEFORE syncing: setRawAttributes($x, true) runs syncOriginal() (which reads
     * getAttributes() → mergeAttributesFromCachedCasts()) BEFORE it clears classCastCache /
     * attributeCastCache, so a value cached from the REFUSED write would be merged over the baseline and
     * copied into `original`. Clearing first (sync=false) then syncing records the true baseline.
     *
     * Scope: this resets the ATTRIBUTE state (attributes + original, including cached casts) only. It
     * does NOT reset the post-save change log (getChanges()/wasChanged() still reflect the attempted
     * write), loaded relations, `exists`/`wasRecentlyCreated`, or any custom in-memory state a record
     * keeps; a caller that reads those after a refused write must not rely on them. And if the reset
     * ITSELF throws (an overridden setRawAttributes, a cast that throws on merge) the instance's
     * attribute state is NOT guaranteed — the reset's own error is swallowed here so it cannot mask the
     * caller's original exception, which the caller rethrows after this returns.
     *
     * @param  array<string, mixed>  $attributes  the pre-call attribute set (the caller's edits included)
     * @param  array<string, mixed>  $original    the pre-call DB-synced original baseline
     * @return bool  true if the instance was reset to its pre-call state; false if the reset itself threw
     *               (state NOT guaranteed) — the unique-retry caller must NOT retry on a polluted instance.
     */
    private function discardRefusedWrite(Model $model, array $attributes, array $original): bool
    {
        try {
            // Rebuild the pre-call (attributes, original) pair with the cast caches CLEARED first, so a
            // value cached from the refused write can't leak into the baseline. In order:
            //   1. attributes := baseline, both cast caches cleared, NO sync (original untouched);
            //   2. syncOriginal(): copy those now-clean attributes into `original` — the true baseline;
            //   3. attributes := pre-call values, caches cleared again; `original` left from step 2.
            // Net: attributes = pre-call values, original = pre-call baseline, caches empty — so
            // isDirty()/getDirty() match the pre-call state exactly, with no cached-cast pollution.
            $model->setRawAttributes($original, false);
            $model->syncOriginal();
            $model->setRawAttributes($attributes, false);

            return true;
        } catch (\Throwable) {
            // The reset itself failed; the instance's attribute state is not guaranteed. Swallow it so
            // the caller's ORIGINAL exception (the one it is mid-throw on) surfaces unmasked, and report
            // the failure so the unique-retry caller does not retry on a polluted instance.
            return false;
        }
    }

    private function nextVersionNumber(Model&Versionable $model): int
    {
        return ((int) $this->scoped($model)->max('version')) + 1;
    }

    /**
     * A query scoped to one record's versions via the polymorphic morph — the record is its lineage.
     *
     * @return Builder<Version>
     */
    private function scoped(Model&Versionable $model): Builder
    {
        return $this->on($model)
            ->where('versionable_type', $model->getMorphClass())
            ->where('versionable_id', $model->getKey());
    }

    /**
     * A version query on the RECORD's connection: a record's history lives where the record does, as its own
     * `versions()` relation already resolves it. On the process default, a central record under tenancy minted its
     * versions into the tenant, outside any transaction its caller held on the record's connection, so a failure after
     * the snapshot rolled back the record but kept the version. A record on the default connection is unchanged.
     *
     * @return Builder<Version>
     */
    private function on(Model&Versionable $model): Builder
    {
        return Version::on($model->getConnectionName());
    }

    /**
     * Narrow a {@see Versionable} to the `Model&Versionable` this Eloquent adapter requires — the one
     * place the port's storage-agnostic socket meets Eloquent. A record that is Versionable but not an
     * Eloquent Model cannot be minted/pinned/scoped here, so it is a hard, loud error rather than a
     * fatal deep in a morph query.
     */
    private function eloquent(Versionable $model): Model&Versionable
    {
        return $model instanceof Model ? $model : throw new InvalidArgumentException(sprintf(
            '%s versions Eloquent models only; %s implements Versionable but does not extend %s.',
            self::class,
            $model::class,
            Model::class,
        ));
    }
}
