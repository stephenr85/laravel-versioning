<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Rushing\Versioning\Tests\Fixtures\FixtureRecord;

/**
 * The versioning LAWS, proven against a throwaway fixture model independent of any real consumer
 * (ticket 04). A reusable package earns trust from its own green, not from a consumer's — this suite
 * mirrors that conformance-kit discipline. Every law here is record-agnostic: the store never
 * inspects snapshot shape, so passing against {@see FixtureRecord} is passing for any Versionable.
 */
function store(): VersionStore
{
    return app(VersionStore::class);
}

function fixture(string $name = 'draft', array $data = ['body' => 'v1']): FixtureRecord
{
    return FixtureRecord::query()->create(['name' => $name, 'data' => $data]);
}

// ── Law 1: immutability ──────────────────────────────────────────────────────────────────────────
// A frozen version can never be rewritten; the only mutation history permits is a rename of `label`.

it('throws when any frozen column on an existing version is dirtied', function (string $column, mixed $value): void {
    $record = fixture();
    $version = store()->snapshot($record);

    $version->{$column} = $value;

    expect(fn () => $version->save())
        ->toThrow(RuntimeException::class, "column [{$column}] cannot be changed");
})->with([
    'versionable_type' => ['versionable_type', 'Some\\Other\\Model'],
    'versionable_id' => ['versionable_id', 'a-different-record'],
    'version' => ['version', 999],
    'snapshot' => ['snapshot', ['tampered' => true]],
    'created_by' => ['created_by', 'someone-else'],
]);

it('allows renaming the mutable label on an existing version', function (): void {
    $record = fixture();
    $version = store()->snapshot($record, label: 'first cut');

    $version->label = 'pre-legal-review';

    expect(fn () => $version->save())->not->toThrow(RuntimeException::class);
    expect($version->fresh()->label)->toBe('pre-legal-review');
});

// ── Law 2: snapshot → restore round-trip ─────────────────────────────────────────────────────────
// snapshot() freezes toVersionSnapshot(); after mutation, restore() applies restoreVersionSnapshot()
// and the live record's content matches the frozen snapshot exactly.

it('freezes the record content into the snapshot at capture time', function (): void {
    $record = fixture('draft', ['body' => 'original']);
    $version = store()->snapshot($record);

    // Mutating the record AFTER the snapshot must not bleed into the frozen row.
    $record->update(['data' => ['body' => 'edited']]);

    expect($version->fresh()->snapshot)->toBe(['name' => 'draft', 'data' => ['body' => 'original']]);
});

it('restores the record content back to a prior snapshot', function (): void {
    $record = fixture('draft', ['body' => 'original']);
    $v1 = store()->snapshot($record);

    $record->update(['name' => 'renamed', 'data' => ['body' => 'edited']]);

    store()->restore($record, $v1->id);

    expect($record->fresh())
        ->name->toBe('draft')
        ->data->toBe(['body' => 'original']);
});

it('resolves and restores a version by its readable slug, not just its uuid', function (): void {
    $record = fixture('draft', ['body' => 'original']);
    store()->snapshot($record);                 // v1
    $v2 = store()->snapshot($record);           // v2

    $record->update(['data' => ['body' => 'edited']]);

    // Default resolver renders `v{n}` — restore by that slug, not the uuid.
    store()->restore($record, $v2->readableVersion());

    expect($v2->readableVersion())->toBe('v2');
    expect($record->fresh()->data)->toBe(['body' => 'original']);
});

it('resolves an unknown ref to null rather than erroring — including a non-uuid ref', function (): void {
    $record = fixture('draft');
    store()->snapshot($record); // v1

    // A non-uuid ref that matches no slug must return null, not throw. On Postgres a bare `whereKey`
    // on the uuid pk would reject a non-uuid string with a cast error; the store gates the key lookup
    // on uuid shape so a slug/garbage ref falls through to the (miss) readable-slug resolution.
    expect(store()->version($record, 'v99'))->toBeNull();
    expect(store()->version($record, 'not-a-uuid-or-slug'))->toBeNull();
});

// ── Law 3: monotonic versions + history ──────────────────────────────────────────────────────────
// Repeated snapshots yield version 1, 2, 3… scoped to the record; history lists newest-first; two
// distinct records allocate independently and never collide.

it('allocates a per-record monotonic version number', function (): void {
    $record = fixture();

    expect(store()->snapshot($record)->version)->toBe(1)
        ->and(store()->snapshot($record)->version)->toBe(2)
        ->and(store()->snapshot($record)->version)->toBe(3);
});

it('lists a record history newest-first', function (): void {
    $record = fixture();
    store()->snapshot($record);
    store()->snapshot($record);
    store()->snapshot($record);

    expect(store()->versions($record)->pluck('version')->all())->toBe([3, 2, 1]);
});

it('scopes versions to their own record — two records do not collide', function (): void {
    $a = fixture('a');
    $b = fixture('b');

    store()->snapshot($a);   // a:v1
    store()->snapshot($b);   // b:v1  (independent sequence, not b:v2)
    store()->snapshot($a);   // a:v2

    expect(store()->versions($a)->pluck('version')->all())->toBe([2, 1])
        ->and(store()->versions($b)->pluck('version')->all())->toBe([1]);

    // The morph scope keeps each record's rows disjoint.
    expect(Version::query()->where('versionable_id', $b->getKey())->count())->toBe(1);
});

it('enforces the monotonic sequence at the database level', function (): void {
    $record = fixture();
    store()->snapshot($record);   // v1

    // A hand-forged duplicate version number for the same record must hit the unique backstop.
    expect(fn () => Version::query()->create([
        'versionable_type' => $record->getMorphClass(),
        'versionable_id' => $record->getKey(),
        'version' => 1,
        'snapshot' => ['name' => 'x', 'data' => null],
    ]))->toThrow(UniqueConstraintViolationException::class);
});

// ── Law 4: HEAD pin moves ────────────────────────────────────────────────────────────────────────
// head() follows snapshot(); after restore() the pin sits on the restored version (the pointer-move
// primitive as built — whether restore ALSO mints a new HEAD version is ticket 05's consumer
// decision, layered on top, NOT baked into the store, so this law holds regardless of 05). The pin
// survives an unrelated save.

it('starts with no HEAD pin before the first snapshot', function (): void {
    expect(store()->head(fixture()))->toBeNull();
});

it('advances the HEAD pin to each new snapshot', function (): void {
    $record = fixture();

    $v1 = store()->snapshot($record);
    expect(store()->head($record)->id)->toBe($v1->id)
        ->and($record->fresh()->head_version)->toBe($v1->id);

    $v2 = store()->snapshot($record);
    expect(store()->head($record->fresh())->id)->toBe($v2->id);
});

it('moves the HEAD pin onto the restored version', function (): void {
    $record = fixture();
    $v1 = store()->snapshot($record);
    $v2 = store()->snapshot($record);

    expect(store()->head($record->fresh())->id)->toBe($v2->id);

    store()->restore($record, $v1->id);

    // The restore pointer-move: HEAD now sits on v1. (Minting a fresh version on restore is 05's call.)
    expect($record->fresh()->head_version)->toBe($v1->id)
        ->and(store()->head($record->fresh())->id)->toBe($v1->id);
});

it('keeps the HEAD pin through an unrelated record save', function (): void {
    $record = fixture();
    $v1 = store()->snapshot($record);

    $record->update(['name' => 'touched-but-not-versioned']);

    expect($record->fresh()->head_version)->toBe($v1->id);
});

// ── Law 5: diff-on-read ──────────────────────────────────────────────────────────────────────────
// The design derives diffs on read from two immutable snapshots and stores nothing. No diff helper
// ships in this slice (it graduates with the version-management UI — see the map's "Not yet
// specified"), so the law to hold NOW is the negative one: a version persists ONLY its own frozen
// snapshot, never a stored delta against a prior version. Diffing is a pure read over that history.

it('stores no delta on a version — only its own immutable snapshot', function (): void {
    $record = fixture('draft', ['body' => 'original']);
    store()->snapshot($record);

    $record->update(['data' => ['body' => 'edited']]);
    $v2 = store()->snapshot($record);

    // v2 holds the full frozen state, self-contained — no diff/base/delta column against v1.
    expect(array_keys($v2->getAttributes()))
        ->not->toContain('diff')
        ->not->toContain('delta')
        ->not->toContain('base_version');

    // A diff is therefore derivable purely from the two snapshots, storing nothing.
    $history = store()->versions($record);
    expect($history->pluck('snapshot')->all())->toBe([
        ['name' => 'draft', 'data' => ['body' => 'edited']],
        ['name' => 'draft', 'data' => ['body' => 'original']],
    ]);
});
