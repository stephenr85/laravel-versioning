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

        // Apply the frozen snapshot back onto the live record and move HEAD to it in one save. This is
        // the pointer-move primitive; a consumer that wants restore to ALSO mint a fresh version
        // (append-only history) layers a snapshot() call on top — the store does not bake that in.
        $model->restoreVersionSnapshot($snapshot);
        $model->setAttribute($model->versionPinAttribute(), $version->id);
        $model->save();
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
                $model->save();

                return $version;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($retriesLeft > 0) {
                return $this->allocate($model, $label, $retriesLeft - 1);
            }

            throw $e;
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
