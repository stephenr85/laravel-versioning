<?php

namespace Rushing\Versioning\Concerns;

use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\MigrationStatus;

/**
 * The async migration DRAIN, record-agnostic: the eager sweep that completes records
 * the read path deferred. A host job composes this trait and points it at the models
 * carrying {@see ReconcilesPayloadOnRead}.
 *
 * The read path migrates only CHEAP rungs inline and marks anything needing an
 * EXPENSIVE rung `pending` without running it; this drain is the eager counterpart —
 * it queries the `pending` rows (indexed on the status column) and runs the FULL
 * ladder via each row's {@see ReconcilesPayloadOnRead::migratePayloadEager()}. On
 * success a row's upgraded payload + advanced version + `current` are written back;
 * on quarantine it is marked `failed`, the original preserved.
 */
trait DrainsPendingMigrations
{
    /**
     * Drain the `pending` rows of one model, chunking so a large backlog does not load
     * every row into memory at once. `$allowLlm` arms the host-bound model rung on the
     * full ladder (the reconciler still self-gates on each schema's opt-in).
     *
     * @param  class-string<Model>  $model
     */
    protected function drainPendingMigrations(string $model, bool $allowLlm = false): void
    {
        $model::query()
            ->where($this->statusColumn(), MigrationStatus::Pending->value)
            ->chunkById(100, function ($rows) use ($allowLlm) {
                foreach ($rows as $row) {
                    /** @var Model&object{migratePayloadEager: callable} $row */
                    $row->migratePayloadEager($allowLlm);
                }
            });
    }

    /**
     * The status column the drain filters `pending` on — the same accessor name the
     * read concern ({@see ReconcilesPayloadOnRead::statusColumn()}) uses, so a host
     * that customizes the column overrides one name, not two.
     */
    protected function statusColumn(): string
    {
        return 'migration_status';
    }
}
