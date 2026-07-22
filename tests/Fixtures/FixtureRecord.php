<?php

declare(strict_types=1);

namespace Rushing\Versioning\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\Versionable;

/**
 * A throwaway {@see Versionable} record for the conformance suite — the package earns trust from its
 * OWN green against a minimal fixture, independent of any real consumer (Composition). Deliberately
 * trivial: a `name` + a free-form `data` bag is all the content, so the tests exercise the versioning
 * LAWS (immutability, monotonic allocation, HEAD pin, round-trip) and never a consumer's shape.
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed> $data
 * @property string|null $head_version
 */
class FixtureRecord extends Model implements Versionable
{
    use HasUuids;
    use VersionableTrait;

    protected $table = 'fixture_records';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * The content frozen into a snapshot — the record's job, opaque to the store.
     *
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array
    {
        return [
            'name' => $this->name,
            'data' => $this->data,
        ];
    }

    /**
     * Apply a frozen snapshot back onto the live record via the normal write path.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->fill([
            'name' => $snapshot['name'],
            'data' => $snapshot['data'],
        ]);
    }
}
