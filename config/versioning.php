<?php

declare(strict_types=1);

/**
 * Versioning package config.
 */
return [

    /*
     * How a version's per-record monotonic integer renders to a human-readable handle. The
     * ReadableVersionResolver is bound from this key:
     *
     *   'sequential' (default) — `v{n}`, label-enriched (`v3-pre-legal-review`). Collision-free by
     *                            construction; naturally ordered; zero extra DB work.
     *   'short-uuid'           — git-style short handle from the version UUID. Opt-in: effectively
     *                            collision-free per-record, NOT globally (that is the trade-off).
     *
     * A consumer needing another scheme rebinds ReadableVersionResolver directly.
     */
    'readable_version' => env('VERSIONING_READABLE', 'sequential'),

];
