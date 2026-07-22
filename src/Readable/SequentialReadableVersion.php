<?php

declare(strict_types=1);

namespace Rushing\Versioning\Readable;

use Illuminate\Support\Str;
use Rushing\Versioning\Contracts\ReadableVersionResolver;
use Rushing\Versioning\Models\Version;

/**
 * The default resolver: `v{version}`, enriched with the label when one exists (`v3-pre-legal-review`).
 * Collision-free by construction — it is the per-record unique monotonic integer, so zero extra DB
 * work and naturally ordered.
 */
final class SequentialReadableVersion implements ReadableVersionResolver
{
    public function toReadable(Version $version): string
    {
        $base = 'v'.$version->version;

        if (filled($version->label)) {
            return $base.'-'.Str::slug($version->label);
        }

        return $base;
    }
}
