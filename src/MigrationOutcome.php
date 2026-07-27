<?php

declare(strict_types=1);

namespace Rushing\Versioning;

/**
 * The outcome of reconciling one persisted payload against a target version — the
 * decision a read (or the eager drain) applies to a versioned record.
 *
 * It is the record-agnostic lift of the Composition-shaped `PayloadMigration` DTO:
 * it carries the {@see MigrationStatus} the record should read at, the payload to
 * surface (the upgraded candidate on a cheap success; the ORIGINAL, untouched, for
 * pending/failed), the version identifier the record should advance to, and whether
 * the caller should WRITE BACK (persist the upgrade + advance the version) so the
 * cost amortizes once.
 *
 * The four migration outcomes are the four primary factories:
 *   - {@see current()}   — already at the target; nothing changes;
 *   - {@see migrated()}  — a cheap rung upgraded it (Current + payload + write-back);
 *   - {@see pending()}   — needs an expensive rung a read must not run (deferred);
 *   - {@see failed()}    — unmigratable by cheap rungs (quarantined, original preserved).
 *
 * `$versionId` is the record-agnostic name for "the resulting version identifier"
 * (a schema `$id` in the schema-ladder adapter); it is deliberately not called
 * `schemaId` so the substrate never names a schema concept.
 */
final class MigrationOutcome
{
    /**
     * @param  array<string, mixed>|null  $payload  the payload to surface, or null when unchanged
     */
    private function __construct(
        public readonly MigrationStatus $status,
        public readonly ?array $payload,
        public readonly ?string $versionId,
        public readonly bool $shouldWriteBack,
    ) {}

    /** Already at the target version — nothing changes. */
    public static function current(?string $versionId): self
    {
        return new self(MigrationStatus::Current, null, $versionId, false);
    }

    /**
     * A cheap rung upgraded the payload: surface the candidate, advance to the new
     * version, and write it back so the migration amortizes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function migrated(array $payload, string $versionId): self
    {
        return new self(MigrationStatus::Current, $payload, $versionId, true);
    }

    /**
     * Upgrading needs an expensive rung the read path must not run. The ORIGINAL
     * payload is surfaced verbatim; the record reads `pending` and is written back so
     * the async drain can find it.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function pending(array $payload, ?string $versionId): self
    {
        return new self(MigrationStatus::Pending, $payload, $versionId, true);
    }

    /**
     * The cheap ladder quarantined the record. The ORIGINAL payload is preserved
     * immutably; the record reads `failed` for HITL. Write back only the STATUS —
     * never the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function failed(array $payload, ?string $versionId): self
    {
        return new self(MigrationStatus::Failed, $payload, $versionId, true);
    }

    /**
     * A NON-mutating read-at-version outcome: the stored payload was validated
     * against a deliberately pinned version and does NOT conform. The original is
     * surfaced verbatim, but — unlike {@see failed()} — NOTHING is written back (no
     * status flip, no version change). Read-at-version is a pure view; it never
     * persists, even when the payload fails to conform.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function failedReadOnly(array $payload, ?string $versionId): self
    {
        return new self(MigrationStatus::Failed, $payload, $versionId, false);
    }
}
