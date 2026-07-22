<?php

declare(strict_types=1);

/**
 * Versioning package config.
 *
 * Scaffold-minimal. The `readableVersion()` seam (ticket 01: default `v{n}`,
 * short-UUID opt-in) and any store tuning bind in ticket 03 — this file only
 * has to exist so the provider's mergeConfigFrom/publishes are real.
 */
return [

    // How a version's monotonic integer renders to humans. `v{n}` by default;
    // a consumer can rebind the readable-version seam in ticket 03.
    'readable_version' => env('VERSIONING_READABLE', 'sequential'),

];
