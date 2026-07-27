<?php

declare(strict_types=1);

namespace Rushing\Versioning;

/**
 * The status a reconciled record reads at — the record-agnostic lift of the
 * migration state a versioned record carries after its stored payload is classified
 * against the current version.
 *
 * The backing values are the strings a consumer persists in its own status column,
 * so a host's Eloquent cast can round-trip them without the substrate ever naming
 * the consumer's enum.
 *
 * The four migration OUTCOMES (current / migrated / pending / failed) collapse onto
 * three statuses: a cheap migration surfaces as {@see self::Current} carrying an
 * upgraded payload + a write-back (see {@see MigrationOutcome::migrated()}), so "migrated" is
 * "current, freshly upgraded" rather than a distinct resting state.
 */
enum MigrationStatus: string
{
    /** At (or advanced to) the current version — nothing deferred. */
    case Current = 'current';

    /** Upgrading needs an expensive rung a read must not run; deferred to the drain. */
    case Pending = 'pending';

    /** No cheap rung could migrate it; quarantined with the original preserved. */
    case Failed = 'failed';
}
