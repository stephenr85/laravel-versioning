<?php

use Rushing\Versioning\MigrationOutcome;
use Rushing\Versioning\MigrationStatus;

/**
 * The record-agnostic outcome value type — the four migration outcomes, lifted from
 * the Composition-shaped DTO. Asserts the factory contract every consumer relies on:
 * which status, whether the payload is the upgraded candidate or the preserved
 * original, and whether the caller should write back.
 */
it('current: at the target version, nothing to surface, no write-back', function () {
    $outcome = MigrationOutcome::current('v/2');

    expect($outcome->status)->toBe(MigrationStatus::Current)
        ->and($outcome->payload)->toBeNull()
        ->and($outcome->versionId)->toBe('v/2')
        ->and($outcome->shouldWriteBack)->toBeFalse();
});

it('migrated: reads Current, surfaces the upgraded candidate, advances + writes back', function () {
    $upgraded = ['title' => 'Aged', 'summary' => ''];
    $outcome = MigrationOutcome::migrated($upgraded, 'v/2');

    expect($outcome->status)->toBe(MigrationStatus::Current)
        ->and($outcome->payload)->toBe($upgraded)
        ->and($outcome->versionId)->toBe('v/2')
        ->and($outcome->shouldWriteBack)->toBeTrue();
});

it('pending: defers with the ORIGINAL preserved, still stamps the deferred state', function () {
    $original = ['title' => 'Keep', 'body' => 'Me'];
    $outcome = MigrationOutcome::pending($original, 'v/1');

    expect($outcome->status)->toBe(MigrationStatus::Pending)
        ->and($outcome->payload)->toBe($original)
        ->and($outcome->versionId)->toBe('v/1')
        ->and($outcome->shouldWriteBack)->toBeTrue();
});

it('failed: quarantines with the ORIGINAL preserved, writes back only the status', function () {
    $original = ['title' => 'Only Title'];
    $outcome = MigrationOutcome::failed($original, 'v/1');

    expect($outcome->status)->toBe(MigrationStatus::Failed)
        ->and($outcome->payload)->toBe($original)
        ->and($outcome->versionId)->toBe('v/1')
        ->and($outcome->shouldWriteBack)->toBeTrue();
});

it('failedReadOnly: a pure view — original preserved, NEVER written back', function () {
    $original = ['body' => 'No title here'];
    $outcome = MigrationOutcome::failedReadOnly($original, 'v/2');

    expect($outcome->status)->toBe(MigrationStatus::Failed)
        ->and($outcome->payload)->toBe($original)
        ->and($outcome->versionId)->toBe('v/2')
        ->and($outcome->shouldWriteBack)->toBeFalse();
});

it('backs the status enum with the strings a host persists', function () {
    expect(MigrationStatus::Current->value)->toBe('current')
        ->and(MigrationStatus::Pending->value)->toBe('pending')
        ->and(MigrationStatus::Failed->value)->toBe('failed');
});
