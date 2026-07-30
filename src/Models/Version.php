<?php

declare(strict_types=1);

namespace Rushing\Versioning\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use Rushing\Versioning\Contracts\ReadableVersionResolver;

/**
 * One IMMUTABLE version of a record: a frozen `snapshot` with a per-record monotonic `version`
 * number. The record is its own lineage — `versionable_type`/`versionable_id` is the polymorphic
 * morph, so there is no separate lineage row and no shared string key (the shape the reference
 * WorkflowDefinitionVersion carries only because one definition is shared across many instances).
 *
 * Immutability is enforced HERE, in the model, exactly as the reference does it: once a version row
 * exists its identity + content ({@see IMMUTABLE}) can never change — an update that dirties any
 * frozen column throws. Only `label` (a human annotation, not history) may be renamed. So "edit"
 * has no path but to fork a NEW version; the store never even tries to rewrite one.
 *
 * Runs on the default connection — under the host's tenancy that is the tenant schema, so versions
 * are tenant data.
 *
 * @property string $id
 * @property string $versionable_type
 * @property string $versionable_id
 * @property int $version
 * @property array<string, mixed> $snapshot
 * @property string|null $label
 * @property string|null $created_by
 */
class Version extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * The version store's table name is a config seam (`versioning.table`, default `versions`) — NOT a
     * hardcoded property. The foundation keeps its independent default (no upward dependency on any
     * consumer), but a composing host that owns a table-prefix idiom (e.g. beam's `Beam::table()`) can
     * repoint every version row through one config key without editing this model. A property default
     * can't call `config()`, so the resolution lives in `getTable()`.
     */
    public function getTable(): string
    {
        return $this->table ?? (string) config('versioning.table', 'versions');
    }

    protected $casts = [
        'snapshot' => 'array',
        'version' => 'integer',
    ];

    /** The columns frozen once the row exists — identity + content a fork must never rewrite. */
    protected const IMMUTABLE = ['versionable_type', 'versionable_id', 'version', 'snapshot', 'created_by'];

    protected static function booted(): void
    {
        // The immutability gate: reject any update that mutates a frozen column on an existing row.
        static::updating(function (self $version): void {
            foreach (self::IMMUTABLE as $column) {
                if ($version->isDirty($column)) {
                    throw new RuntimeException(
                        "Version [{$version->id}] is immutable; column [{$column}] cannot be changed. "
                        .'A new snapshot forks a new version instead of rewriting one.',
                    );
                }
            }
        });
    }

    /**
     * The record this version belongs to.
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The human-readable handle for this version, via the bound {@see ReadableVersionResolver}
     * (default `v{n}`). The store resolves a slug back through the same resolver.
     */
    public function readableVersion(): string
    {
        return app(ReadableVersionResolver::class)->toReadable($this);
    }
}
