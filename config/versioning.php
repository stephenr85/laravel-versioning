<?php

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

    /*
     * The physical table the Version model reads/writes. Defaults to `versions` — the foundation holds
     * NO opinion beyond that and depends on no consumer. A composing host that owns a table-prefix idiom
     * repoints this ONE key (e.g. splicewire-app sets it to beam's `Beam::table('versions')` →
     * `beam_versions`) and every version row follows, without editing the foundation model.
     */
    'table' => env('VERSIONING_TABLE', 'versions'),

];
