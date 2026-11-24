<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Exceptions\VersionWriteRefused;
use Rushing\Versioning\Models\Version;
use Rushing\Versioning\Tests\Fixtures\CachingCastFixtureRecord;
use Rushing\Versioning\Tests\Fixtures\FixtureRecord;
use Rushing\Versioning\Tests\Fixtures\ResetThrowingFixtureRecord;
use Rushing\Versioning\Tests\Fixtures\SelfSavingFixtureRecord;
use Rushing\Versioning\Tests\Fixtures\ThrowingRestoreFixtureRecord;

/**
 * The store moves a HEAD pin with a single `save()` on the snapshot and restore paths. A halting
 * `saving`/`updating` listener makes that `save()` return false WITHOUT persisting; the store must
 * refuse loudly rather than swallow the boolean and leave a half-applied operation that the caller
 * believes succeeded. The laws below pin that refusal AND the cleanup it owes: the mint/restore rolls
 * back as one transaction, nothing of the refused write survives on the instance (including a value
 * cached behind a class cast, and even when the reset itself throws), a caller's OWN pre-call edit is
 * preserved, ANY throwable (not only the veto) triggers the same reset, and the refusal names its
 * subject off the message. Each law below reads red against the pre-fix store — with ONE exception,
 * noted where it sits: the restore exception-factory test at the end is a coverage fold (green at
 * base AND cand), folding the restore path into the subject-naming law the snapshot path already had.
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

// ── restore is atomic: a self-saved content write rolls back with the vetoed pin-move ───────────────

it('rolls back a self-saving restore whose content committed when the pin-move is then vetoed', function (): void {
    // A record that SAVES its own content on restore (the real-consumer shape). Two snapshots, so the
    // restore genuinely MOVES the pin — the write the veto targets.
    $record = SelfSavingFixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'v1']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);                                           // head → v1 (content v1)
    $record->update(['name' => 'renamed', 'data' => ['body' => 'v2']]);
    $v2 = $store->snapshot($record);                                           // head → v2 (content v2)

    // Veto ONLY the pin-move save (head_version dirty), so the self-saving restoreVersionSnapshot()'s
    // OWN content save is allowed to commit first — the split-write the fix must make atomic.
    SelfSavingFixtureRecord::saving(fn ($m) => $m->isDirty('head_version') ? false : null);

    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(VersionWriteRefused::class);

    // Atomic: the content the self-saving restore wrote (v1) rolled back WITH the vetoed pin-move, so
    // the record still holds its v2 content AND its v2 pin — neither half of the restore survived.
    expect($record->fresh())
        ->name->toBe('renamed')
        ->data->toBe(['body' => 'v2'])
        ->head_version->toBe($v2->id);
});

// ── after a refusal the SAME instance is clean: a later save() stores nothing of the refused write ──

it('leaves the instance clean after a refused restore, so a later save() stores nothing', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'original']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);                                           // head → v1
    $record->update(['name' => 'renamed', 'data' => ['body' => 'edited']]);
    $v2 = $store->snapshot($record);                                           // head → v2

    // Veto the restore, then LIFT the veto and save the SAME instance.
    $veto = true;
    FixtureRecord::saving(function () use (&$veto) {
        return $veto ? false : null;
    });

    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(VersionWriteRefused::class);

    $veto = false;
    $record->save();

    // The refused restore (name=draft, data=original, pin=v1) must NOT have ridden the later save in on
    // the still-dirty instance: the record stays at its pre-restore v2 state.
    expect($record->fresh())
        ->name->toBe('renamed')
        ->data->toBe(['body' => 'edited'])
        ->head_version->toBe($v2->id);
});

it('leaves the instance clean after a refused snapshot, so a later save() leaves no dangling pin', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'v1']]);
    $store = app(VersionStore::class);

    $veto = true;
    FixtureRecord::saving(function () use (&$veto) {
        return $veto ? false : null;
    });

    expect(fn () => $store->snapshot($record))
        ->toThrow(VersionWriteRefused::class);

    // The minted version rolled back with the transaction.
    expect(Version::query()->count())->toBe(0);

    $veto = false;
    $record->save();

    // The refused HEAD pin pointed at the rolled-back version; if it rode the later save in on the
    // still-dirty instance, head_version would now reference a version that does not exist. It must
    // stay null — no dangling pin.
    expect($record->fresh()->head_version)->toBeNull();
});

// ── a caller's OWN pre-call edit survives a refused write (the reset preserves the dirty set) ────────

it('preserves a caller pre-call edit through a vetoed snapshot, so a later save still writes it', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'v1'], 'note' => null]);
    $store = app(VersionStore::class);

    // The caller makes its OWN unsaved edit to `note` — a field the snapshot path never touches —
    // BEFORE handing the record to the store. It is a genuine pending change, not the store's business.
    $record->note = 'caller-edit';
    expect($record->isDirty('note'))->toBeTrue();

    // Veto the HEAD-pin write, then LIFT the veto and save the SAME instance. A by-reference closure
    // (not an arrow fn, which binds $veto by value at creation and would never see the lift below).
    $veto = true;
    FixtureRecord::saving(function () use (&$veto) {
        return $veto ? false : null;
    });

    expect(fn () => $store->snapshot($record))
        ->toThrow(VersionWriteRefused::class);

    // The reset restored the EXACT pre-call dirty set: the caller's `note` edit is still pending (a
    // blanket syncOriginal would have marked it clean), and nothing of the refused write is — the pin
    // the store attempted is gone.
    expect($record->isDirty('note'))->toBeTrue()
        ->and($record->note)->toBe('caller-edit')
        ->and($record->head_version)->toBeNull();

    $veto = false;
    $record->save();

    // So a later save on the still-live instance WRITES the caller's edit (it was never dropped) and
    // leaves no dangling pin pointing at the rolled-back version.
    expect($record->fresh())
        ->note->toBe('caller-edit')
        ->head_version->toBeNull();
});

it('preserves a caller pre-call edit through a vetoed self-saving restore, so a later save still writes it', function (): void {
    // A SELF-SAVING restore commits its own content (and syncs the model's `original` baseline) before
    // the separate pin-move is vetoed. The reset must restore BOTH the attributes AND that baseline —
    // restoring attributes alone would leave the caller's `note` edit measured against a polluted
    // baseline, so it would read clean and a later save would drop it.
    $record = SelfSavingFixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'v1'], 'note' => null]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);                                           // head → v1 (content v1)
    $record->update(['name' => 'renamed', 'data' => ['body' => 'v2']]);
    $store->snapshot($record);                                                 // head → v2 (content v2)

    // Caller's OWN unsaved edit to `note` — untouched by restoreVersionSnapshot (name/data only).
    $record->note = 'caller-edit';
    expect($record->isDirty('note'))->toBeTrue();

    // Veto ONLY the pin-move save, so the self-saving restore's content save commits first (polluting
    // `original`), then the pin-move is refused — the split the reset must fully unwind.
    $veto = true;
    SelfSavingFixtureRecord::saving(fn ($m) => ($veto && $m->isDirty('head_version')) ? false : null);

    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(VersionWriteRefused::class);

    // The caller's `note` edit is still pending against the TRUE pre-call baseline ...
    expect($record->isDirty('note'))->toBeTrue()
        ->and($record->note)->toBe('caller-edit');

    $veto = false;
    $record->save();

    // ... and a later save writes it, while nothing the refused restore did to name/data survives.
    expect($record->fresh())
        ->note->toBe('caller-edit')
        ->name->toBe('renamed')
        ->data->toBe(['body' => 'v2']);
});

// ── ANY throwable mid-restore (not only the veto) resets the instance and surfaces unchanged ────────

it('resets the instance and rethrows unchanged when a restore hook throws after mutating', function (): void {
    // The restore hook mutates (and commits) its content, then throws a plain RuntimeException — NOT a
    // VersionWriteRefused. The store must still roll the row back, put the instance back to its
    // pre-call state, and let the ORIGINAL exception surface unchanged.
    $record = ThrowingRestoreFixtureRecord::query()->create(['name' => 'current', 'data' => ['body' => 'now'], 'note' => null]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);                                           // head → v1 (content "now")
    $record->update(['name' => 'edited', 'data' => ['body' => 'later']]);      // DB now: edited/later, head v1

    // The ORIGINAL exception surfaces unchanged (not swallowed, not rewrapped as VersionWriteRefused).
    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(RuntimeException::class, ThrowingRestoreFixtureRecord::BOOM);

    // The row rolled back with the aborted transaction — the hook's committed content did not land.
    expect($record->fresh())
        ->name->toBe('edited')
        ->data->toBe(['body' => 'later']);

    // The in-memory instance was reset too: it carries none of the mutation the hook applied, so a
    // later save stores nothing of the failed restore (pre-fix: only a VersionWriteRefused reset the
    // instance, so an arbitrary throwable left the hook's snapshot values dirty and a save re-applied them).
    expect($record->isDirty())->toBeFalse()
        ->and($record->name)->toBe('edited')
        ->and($record->data)->toBe(['body' => 'later']);

    $record->save();
    expect($record->fresh())
        ->name->toBe('edited')
        ->data->toBe(['body' => 'later']);
});

// ── the reset survives a CACHED class cast on a snapshotted field (clear the cache before syncing) ──

it('resets a cached class-cast attribute to the pre-call baseline, not the refused write', function (): void {
    $record = CachingCastFixtureRecord::query()->create(['name' => 'original', 'data' => ['k' => 'v0']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);                                 // head → v1 (data k=>v0)
    $record->update(['name' => 'current', 'data' => ['k' => 'current']]);

    // Read the class-cast attribute so its resolved value is held in the model's classCastCache. The
    // cache tracks the live 'current' now, and will track the REFUSED 'v0' write mid-restore — the
    // pollution source the reset must not copy into the baseline.
    expect($record->data['k'])->toBe('current')
        ->and($record->isDirty())->toBeFalse();                      // just saved: a clean baseline

    // The restore's pin-move write is vetoed — and the veto listener READS the class cast on the
    // instance first, so its resolved value (the REFUSED v0 the restore just applied) is held in
    // classCastCache when the reset runs. restoreVersionSnapshot() filled `data` as a plain array,
    // which clears the cache, so WITHOUT this read the cache is empty at reset time and the old
    // sync-then-clear order passes too — proving nothing. This read is what makes the mutant die.
    $veto = true;
    CachingCastFixtureRecord::saving(function ($model) use (&$veto) {
        if (! $veto) {
            return null;
        }

        $model->data; // resolve + cache the attempted v0

        return false;
    });

    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(VersionWriteRefused::class);

    // The reset put the baseline back to the PRE-CALL state — not the refused v0 the cast cache held
    // mid-restore. Pre-fix the sync-then-clear order synced `original` from getAttributes() while the
    // cache still held v0, merging it over the baseline: getRawOriginal() then read v0 and isDirty() lied.
    expect(json_decode($record->getRawOriginal('data'), true))->toBe(['k' => 'current'])
        ->and($record->isDirty())->toBeFalse();

    // Veto lifted: a later save stores nothing of the refused write — the row stays 'current'.
    $veto = false;
    $record->save();
    expect($record->fresh()->data['k'])->toBe('current');
});

// ── a reset that ITSELF throws must not mask the original refusal ────────────────────────────────────

it('surfaces the original refusal unchanged even when the instance reset itself throws', function (): void {
    $record = ResetThrowingFixtureRecord::query()->create(['name' => 'original', 'data' => ['k' => 'v0']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);
    $record->update(['name' => 'current', 'data' => ['k' => 'current']]);

    // The pin-move write is vetoed, AND the reset the refusal triggers will itself throw.
    ResetThrowingFixtureRecord::saving(fn () => false);
    $record->failReset = true;

    // The ORIGINAL VersionWriteRefused must surface, NOT the reset's own RuntimeException: a reset that
    // fails must not rewrite why the write was refused (pre-fix the reset ran uncaught, so its
    // RESET_BOOM propagated and masked the refusal).
    expect(fn () => $store->restore($record, $v1->id))
        ->toThrow(VersionWriteRefused::class);
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

// ── a pin-save unique collision on EVERY attempt resets the instance and leaves no version ──────────

it('resets the instance and leaves no version when every snapshot attempt hits a unique collision', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'original', 'data' => ['body' => 'v1']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);                        // head → v1
    $record->update(['name' => 'current', 'data' => ['body' => 'current']]);

    $pinBefore = $record->getAttribute($record->versionPinAttribute());
    expect($record->isDirty())->toBeFalse();                // clean pre-call baseline (pin = v1)

    // A unique collision raised by the PIN SAVE (not the version insert) throws AFTER the pin attribute
    // is assigned, so each attempt mutates the instance before it throws. The record's `saving` listener
    // throws a UniqueConstraintViolation on every try; the one allowed retry re-enters allocate() and
    // collides again, exhausting the retry.
    FixtureRecord::saving(function (): void {
        throw new UniqueConstraintViolationException(
            'testing',
            'insert into "versioning_records" ...',
            [],
            new RuntimeException('duplicate pin'),
        );
    });

    // The collision surfaces unchanged after the retry is exhausted.
    expect(fn () => $store->snapshot($record))
        ->toThrow(UniqueConstraintViolationException::class);

    // The instance was reset to the TRUE pre-call pair before EACH rethrow, so it carries NONE of the
    // attempted pins: the pin attribute and the dirty set equal pre-call. Pre-fix the unique catch reset
    // ran only on the EXHAUSTED path, over attributes the first attempt had already polluted with its
    // failed pin (the recursion re-captured the polluted instance) — so the failed pin was put BACK.
    expect($record->getAttribute($record->versionPinAttribute()))->toBe($pinBefore)
        ->and($record->isDirty())->toBeFalse();

    // Both attempts' freshly minted version rows rolled back with their transactions — only v1 remains.
    expect($store->versions($record)->pluck('id')->all())->toBe([$v1->id]);
});

// Coverage fold (green at base AND cand): the RESTORE factory carries the subject on properties and
// keeps a clean message, exactly as the snapshot path above — the snapshot path had this test, restore
// did not.
it('names the subject on properties and keeps its identity out of the message (restore path)', function (): void {
    $record = FixtureRecord::query()->create(['name' => 'draft', 'data' => ['body' => 'original']]);
    $store = app(VersionStore::class);

    $v1 = $store->snapshot($record);
    $record->update(['name' => 'renamed', 'data' => ['body' => 'edited']]);
    FixtureRecord::saving(fn () => false);

    try {
        $store->restore($record, $v1->id);
        $this->fail('restore should throw VersionWriteRefused when the write is vetoed.');
    } catch (VersionWriteRefused $e) {
        expect($e->subjectType)->toBe($record->getMorphClass())
            ->and($e->subjectKey)->toBe($record->getKey());

        expect($e->getMessage())
            ->not->toContain($record->getMorphClass())
            ->not->toContain((string) $record->getKey());
    }
});
