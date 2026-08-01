<?php

namespace Rushing\Versioning\Contracts;

use Rushing\Versioning\Models\Version;

/**
 * The socket a record opts into to become versionable — the plug side of the linear "immutable named
 * snapshot + HEAD pin + restore" seam. A model declares exactly three things, never more (mirrors the
 * minimal WorkflowManaged split in splicewire/laravel-beam-workflows):
 *
 *   - {@see toVersionSnapshot()}      what content to freeze into a version's `snapshot` (the record's
 *                                     job — the store is record-agnostic and never inspects shape).
 *   - {@see restoreVersionSnapshot()} how to apply a frozen snapshot back onto the live record.
 *   - {@see versionPinAttribute()}    the column pinning HEAD — the version this record currently sits
 *                                     on (default `head_version`, overridable per model).
 *
 * The {@see \Rushing\Versioning\Concerns\Versionable} trait defaults the pin and supplies relation
 * sugar, leaving only the two content methods abstract. A record NOT implementing this is simply
 * unversioned — there is no special case, only the absence of the contract.
 */
interface Versionable
{
    /**
     * The content to freeze into this snapshot. Serialization is the record's concern, not the
     * store's — return any JSON-encodable array.
     *
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array;

    /**
     * Apply a previously frozen snapshot back onto the live record. Reuse the record's normal write
     * path; the store calls this inside {@see VersionStore::restore()}.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void;

    /**
     * The attribute holding the HEAD pin — the id of the {@see Version}
     * this record currently sits on. Defaulted to `head_version` by the trait.
     */
    public function versionPinAttribute(): string;
}
