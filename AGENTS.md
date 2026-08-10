> You are in **rushing/laravel-versioning** — record-agnostic versioning seams for Laravel (immutable snapshot/restore plus a migration-across-versions port).

A record-agnostic linear versioning seam for Laravel: any schema-driven record opts into immutable
named snapshots with a HEAD pointer and restore, via the `Versionable` contract/trait, the `Version`
model, and a `VersionStore`. It also exposes the `Migrator` port (migrate a payload version-to-version)
for schema-driven migration-across-versions — the contract only; a concrete engine binds to it via an
engine-tier adapter. The package ships the socket; the consuming app supplies the record and owns the
tenant migration.
