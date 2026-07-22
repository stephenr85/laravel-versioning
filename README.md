# rushing/laravel-versioning

A **record-agnostic linear versioning seam** for Laravel: any schema-driven record opts into
*immutable named snapshots + a HEAD pointer + restore*. The linear socket — `v1 → v2 → v3` plus a
`head_version` pin; branch/merge is a separate, later feature modelled on top.

Generic Laravel infrastructure tier (peer of `rushing/laravel-request-logs`, `rushing/laravel-popcorn`),
**not** the Splicewire engine. The package ships the socket; the app supplies the record and owns the
tenant migration.

## What it ships

- **`Versionable`** — contract + trait. A record implements `toVersionSnapshot(): array` /
  `restoreVersionSnapshot(array $snapshot): void` and carries a `head_version` pin.
- **`Version`** — an immutable, polymorphic snapshot model. Editing a frozen column throws: an edit
  forks a new version, it never rewrites one.
- **`VersionStore`** — `snapshot` / `versions` / `version` / `restore` / `head`. Serialization is the
  *model's* job; the store is record-agnostic.

## What it does not ship

- **No migration.** The polymorphic `versions` table is authored by the consuming app under its own
  tenant migration path (the app owns tenant DDL; the package ships only models/contracts/store).
- **No branch/merge.** Linear-only by design.

## Status

Scaffold (ticket 02). The model, contract/trait, and store land in ticket 03, against the locked
store contract. See the `record-versioning` wayfinder map.
