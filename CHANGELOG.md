# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `doctrine:projections` generates read-only Eloquent models from Doctrine
  metadata, reading foreign keys, join tables and key types from the mapping
  rather than guessing them from Laravel conventions.
- Three-layer write lock: model events, `ReadOnlyBuilder` for bulk builder
  writes, and `ReadOnlyBelongsToMany` for pivot writes.
- `ReadOnlyBuilderCoverageTest` guards the hand-maintained blocklist against
  Laravel upgrades introducing new write methods.
- Single table inheritance support — subclasses get a discriminator global
  scope so they cannot return their siblings' rows.
- `doctrine:diff` generates a Laravel migration from the entity/database
  difference, classifying every statement as fatal, destructive or a warning.
- `--check` on `doctrine:projections` for CI: fails when regeneration would
  change the committed files.
- Optional `SharedPdoDriver` so Doctrine can run on Laravel's own PDO.
- PHPStan at level max in CI, with no baseline and no suppressions.

### Refused on purpose

- Class table inheritance (JOINED) — an entity spanning several tables cannot
  be a single Eloquent model.
- Composite primary keys — emitted with `$primaryKey = null` and a warning
  rather than silently reduced to the first column.
- Two entities sharing a short name — their projections would overwrite each
  other's file.
