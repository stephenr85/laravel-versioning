<?php

use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Exceptions\VersionWriteRefused;
use Rushing\Versioning\Models\Version;
use Rushing\Versioning\Tests\Fixtures\FixtureRecord;

/**
 * The store moves a HEAD pin with a single `save()` on the snapshot and restore paths. A halting
 * `saving`/`updating` listener makes that `save()` return false WITHOUT persisting; the store must
 * refuse loudly rather than swallow the boolean and leave a half-applied operation that the caller
 * believes succeeded. These two laws pin that refusal — red against the pre-fix store, which ignored
 * the boolean.
 */

// ── snapshot: a vetoed HEAD-pin write rolls the minted version back ────────────────────────────────

it('refuses a snapshot whose HEAD-pin write is vetoed, and rolls the minted version back', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'v1']]);

    // The record's own write is refused — the pin-move save returns false.
    FixtureRecord::saving(fn () => false);

    expect(fn () => app(VersionStore::class)->snapshot($record))
        ->toThrow(VersionWriteRefused::class);

    // The mint runs inside a transaction, so the refusal rolls the version row back: nothing persists.
    expect(Version::query()->count())->toBe(0)
        ->and($record->fresh()->head_version)->toBeNull();
});

// ── restore: a vetoed restore leaves the record untouched ──────────────────────────────────────────

it('refuses a restore whose write is vetoed, and leaves the record unchanged', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'original']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);
    $record->update(['name' => 'renamed', 'data' => ['body' => 'edited']]);

    // The restoring write is refused — the snapshot is never applied back onto the record.
    FixtureRecord::saving(fn () => false);

    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(VersionWriteRefused::class);

    // Untouched: the snapshot's values did NOT land; the record still holds its edited state.
    expect($record->fresh())
        ->name->toBe('renamed')
        ->data->toBe(['body' => 'edited']);
});

// ── the refusal carries the subject on properties, not inside its message ───────────────────────────

it('names the subject on properties and keeps its identity out of the message', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'v1']]);
    FixtureRecord::saving(fn () => false);

    try {
        app(VersionStore::class)->snapshot($record);
        $this->fail('snapshot should throw VersionWriteRefused when the HEAD-pin write is vetoed.');
    } catch (VersionWriteRefused $e) {
        // The subject is readable off the exception, so a host can key its own user-facing message.
        expect($e->subjectType)->toBe($record->getMorphClass())
            ->and($e->subjectKey)->toBe($record->getKey());

        // The message is generic: it leaks neither the morph type nor the key an owner must not see.
        expect($e->getMessage())
            ->not->toContain($record->getMorphClass())
            ->not->toContain((string) $record->getKey());
    }
});
