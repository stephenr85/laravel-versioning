<?php

namespace Rushing\Versioning\Contracts;

use Rushing\Versioning\Models\Version;
use Rushing\Versioning\Readable\SequentialReadableVersion;
use Rushing\Versioning\Readable\ShortUuidReadableVersion;

/**
 * The bindable seam that renders a version's monotonic integer to a human-readable handle — kept OFF
 * the {@see Versionable} contract so a consumer can swap the derivation generically without touching
 * a model. The container binds one implementation (config `versioning.readable_version`); a consumer
 * may rebind it.
 *
 * The default {@see SequentialReadableVersion} renders `v{n}`
 * (label-enriched: `v3-pre-legal-review`) — collision-free by construction, since it is the
 * per-record unique integer. A git-style short-UUID handle ships as the opt-in alternative
 * ({@see ShortUuidReadableVersion}).
 *
 * The same resolver is the reverse index: {@see VersionStore::version()}
 * and ::restore() accept a slug and match it back through `toReadable()`.
 */
interface ReadableVersionResolver
{
    public function toReadable(Version $version): string;
}
