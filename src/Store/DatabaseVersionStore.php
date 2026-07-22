<?php

declare(strict_types=1);

namespace Rushing\Versioning\Store;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Rushing\Versioning\Contracts\Versionable;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;

/**
 * The concrete, record-agnostic store. Everything that freezes, lists, resolves, or restores a
 * version goes through here, so the monotonic-allocation + HEAD-pin discipline lives in exactly one
 * place. The record is its own lineage: rows are scoped by the polymorphic morph
 * (`versionable_type`/`versionable_id`), never a lineage key.
 *
 * Snapshot serialization is the record's job ({@see Versionable::toVersionSnapshot()}); this store
 * never inspects snapshot shape — that is what makes it consumable by any schema-driven record.
 *
 * @see VersionStore for the surface contract.
 */
final class DatabaseVersionStore implements VersionStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly AuthFactory $auth,
    ) {}

    public function snapshot(Versionable $model, ?string $label = null): Version
    {
        // `next = max(version) + 1` inside the insert's transaction; the unique
        // (versionable_type, versionable_id, version) index is the correctness backstop — a colliding
        // concurrent snapshot fails loudly and we retry exactly once. No row lock in v1: snapshots are
        // explicit, per-record acts, so contention is effectively zero.
        return $this->allocate($model, $label, retriesLeft: 1);
    }

    public function versions(Versionable $model): Collection
    {
        return $this->scoped($model)->orderByDesc('version')->get();
    }

    public function version(Versionable $model, string $ref): ?Version
    {
        $byId = $this->scoped($model)->whereKey($ref)->first();

        if ($byId !== null) {
            return $byId;
        }

        // Fall back to the readable slug — resolved back through the same resolver the model renders.
        return $this->versions($model)->first(
            static fn (Version $version): bool => $version->readableVersion() === $ref,
        );
    }

    public function restore(Versionable $model, string $ref): void
    {
        $version = $this->version($model, $ref);

        if ($version === null) {
            throw new InvalidArgumentException(
                "No version [{$ref}] belongs to this record; cannot restore.",
            );
        }

        // Apply the frozen snapshot back onto the live record and move HEAD to it in one save. This is
        // the pointer-move primitive; a consumer that wants restore to ALSO mint a fresh version
        // (append-only history) layers a snapshot() call on top — the store does not bake that in.
        $model->restoreVersionSnapshot($version->snapshot);
        $model->setAttribute($model->versionPinAttribute(), $version->id);
        $model->save();
    }

    public function head(Versionable $model): ?Version
    {
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
    private function allocate(Versionable $model, ?string $label, int $retriesLeft): Version
    {
        try {
            return $this->db->connection()->transaction(function () use ($model, $label): Version {
                $version = Version::query()->create([
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

    private function nextVersionNumber(Versionable $model): int
    {
        return ((int) $this->scoped($model)->max('version')) + 1;
    }

    /**
     * A query scoped to one record's versions via the polymorphic morph — the record is its lineage.
     *
     * @return Builder<Version>
     */
    private function scoped(Versionable $model): Builder
    {
        return Version::query()
            ->where('versionable_type', $model->getMorphClass())
            ->where('versionable_id', $model->getKey());
    }
}
