<?php

declare(strict_types=1);

namespace Rushing\Versioning\Readable;

use Illuminate\Support\Str;
use Rushing\Versioning\Contracts\ReadableVersionResolver;
use Rushing\Versioning\Models\Version;

/**
 * The OPT-IN alternative: a git-style short handle from the version UUID's leading hex, label-enriched
 * when present (`4f3a9b1c-pre-legal-review`). Scoped per-record it is effectively collision-free (a
 * record won't hold thousands of versions); globally it is NOT, which is precisely why it is opt-in —
 * bind it via config `versioning.readable_version = 'short-uuid'` with eyes open.
 */
final class ShortUuidReadableVersion implements ReadableVersionResolver
{
    /** How many leading hex chars of the UUID form the short handle. */
    private const LENGTH = 8;

    public function toReadable(Version $version): string
    {
        $tail = substr(str_replace('-', '', (string) $version->id), 0, self::LENGTH);

        if (filled($version->label)) {
            return $tail.'-'.Str::slug($version->label);
        }

        return $tail;
    }
}
