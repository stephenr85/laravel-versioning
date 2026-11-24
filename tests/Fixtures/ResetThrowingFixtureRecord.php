<?php

namespace Rushing\Versioning\Tests\Fixtures;

use RuntimeException;

/**
 * A {@see FixtureRecord} whose setRawAttributes() throws once {@see $failReset} is armed — standing in
 * for a record whose reset can itself fail (an overridden setRawAttributes, or a cast that throws when
 * its value is merged). The store's post-refusal reset calls setRawAttributes, so arming this lets a
 * test prove the ORIGINAL refusal still surfaces: a reset that throws must not mask why the write was
 * refused. Shares the table and snapshot shape; only setRawAttributes differs.
 */
class ResetThrowingFixtureRecord extends FixtureRecord
{
    public const RESET_BOOM = 'setRawAttributes failed during the instance reset';

    /** Armed AFTER setup so the throw lands only on the post-refusal reset, never on hydration. */
    public bool $failReset = false;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        if ($this->failReset) {
            throw new RuntimeException(self::RESET_BOOM);
        }

        return parent::setRawAttributes($attributes, $sync);
    }
}
