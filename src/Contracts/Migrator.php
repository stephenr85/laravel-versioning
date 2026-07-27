<?php

declare(strict_types=1);

namespace Rushing\Versioning\Contracts;

use Rushing\Versioning\MigrationOutcome;

/**
 * The migration PORT: run a stored payload from one version to another and report
 * the {@see MigrationOutcome}.
 *
 * This is the one behavioral seam every $id-versioned record can ride. It is
 * version-generic in signature — `$from` / `$to` are opaque version identifiers the
 * concrete migrator knows how to resolve (a schema `$id` in the schema-ladder
 * adapter) — and record-agnostic: the substrate never sees a schema document, a
 * ladder, or a rung. The concrete engine (e.g. schemastud's `MigrationLadder`) is
 * bound to this port by an engine-tier adapter, so the substrate holds only the
 * contract and the dependency direction stays foundation -> up, never up ->
 * foundation (ADR-0092).
 *
 * A migrator MUST preserve the original payload immutably on a quarantine and MUST
 * never mutate its input.
 */
interface Migrator
{
    /**
     * Migrate `$payload` (stored under `$from`) toward `$to`, returning the outcome.
     *
     * @param  array<string, mixed>  $payload  the stored record payload
     * @param  string|null  $from  the version the payload was written under (null = unknown)
     * @param  string|null  $to  the version to migrate toward (null = no resolvable target)
     */
    public function migrate(array $payload, ?string $from, ?string $to): MigrationOutcome;
}
