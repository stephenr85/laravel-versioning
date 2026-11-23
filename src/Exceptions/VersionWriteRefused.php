<?php

namespace Rushing\Versioning\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when the store's own write to the record is refused — `save()` returned false because a
 * halting `saving`/`updating` listener (a guard, a policy, a tenancy veto) vetoed it. When it is
 * raised, NOTHING the store meant to persist has landed.
 *
 * The store moves a HEAD pin with a single `save()` on two paths — minting a version
 * ({@see \Rushing\Versioning\Store\EloquentVersionStore::snapshot()}) and restoring one
 * ({@see \Rushing\Versioning\Store\EloquentVersionStore::restore()}). A silently-ignored boolean on
 * either would leave the record's HEAD pin unmoved while the caller believes the operation
 * succeeded: for a snapshot, a freshly minted version row with nothing pointing at it (the mint runs
 * inside a transaction, so this refusal rolls it back); for a restore, a record the caller thinks is
 * reverted but is not.
 *
 * The refusal is loud but its message carries NO record identity: the subject's morph type and key
 * ride on {@see $subjectType}/{@see $subjectKey} so a host can read them and build its OWN
 * user-facing message, instead of forwarding an internal `[type:id]` string the record owner should
 * never see.
 */
class VersionWriteRefused extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $subjectType,
        public readonly int|string $subjectKey,
    ) {
        parent::__construct($message);
    }

    public static function snapshot(Model $model): self
    {
        return new self(
            'Refused to record a version: the HEAD-pin write was vetoed, so the version was rolled back and nothing persisted.',
            $model->getMorphClass(),
            $model->getKey(),
        );
    }

    public static function restore(Model $model): self
    {
        return new self(
            'Refused to restore the record: the write was vetoed, so the record is unchanged.',
            $model->getMorphClass(),
            $model->getKey(),
        );
    }
}
