# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **Generated migrations are now atomic where the database can roll DDL
  back** (SQLite, PostgreSQL). Laravel's SQLite grammar reports
  `supportsSchemaTransactions() === false`, so it runs migrations
  unwrapped — and on SQLite a column change is a table rebuild. A failure
  halfway through one left the table dropped, recreated and empty.

  Found by testing what happens when a migration fails rather than when it
  succeeds: tightening a column to `NOT NULL` while rows held `NULL`
  emptied a table of eight rows, and the migration was not even recorded
  as run. The same failure now leaves every row untouched.

  MySQL and MariaDB implicitly commit on DDL, so nothing is wrapped there.

## [0.2.0] — 2026-07-30

### Changed

- **Renaming a column on SQLite no longer requires `--allow-destructive`.**
  SQLite has no `ALTER COLUMN`, so a rename is a table rebuild, and every
  rebuild was treated as data loss. `doctrine:diff` now reads the columns
  the table currently has and passes the rebuild when every one of them is
  carried across, reporting `Rebuilt in place: <table>`. A rebuild that
  leaves a column behind still needs the flag.

  The check compares against the live table on purpose. Comparing what the
  rebuild saves against what it restores is tautological — DBAL parks
  exactly what it means to carry, and omits a dropped column from the SQL
  entirely, so a drop and a rename are textually identical. That first
  attempt would have waved real data loss through; the integration test
  against real `SchemaTool` output is what caught it.

- `StatementClassifier` takes an optional second argument, a map of table
  to its current columns. Without it no rebuild is ever called lossless,
  so existing callers keep the old, safe behaviour.
- `ClassifiedStatements` gained `rebuiltTables`.

## [0.1.1] — 2026-07-30

### Fixed

- Dropped the Laravel 11 constraint: every 11.x release is blocked by
  security advisories, so Composer cannot install it — claiming support was
  meaningless.
- Loosened the `symfony/cache` dev constraint, which required PHP 8.4.1 and
  therefore made the PHP 8.3 the package claims untestable.
- The test EntityManager no longer forces native lazy objects, which need
  PHP 8.4.
- CI now pins `laravel/framework` and the matching `orchestra/testbench`
  instead of the individual `illuminate/*` packages. The framework
  *replaces* those, so pinning both was unsatisfiable and every matrix job
  failed before running a single test.

## [0.1.0] — 2026-07-30

First release.

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
- `entities.only` / `entities.except` config to choose what gets projected;
  relations to excluded entities are skipped with a warning rather than
  emitted as references to classes that do not exist.

### Refused on purpose

- Class table inheritance (JOINED) — an entity spanning several tables cannot
  be a single Eloquent model.
- Composite primary keys — emitted with `$primaryKey = null` and a warning
  rather than silently reduced to the first column.
- Two entities sharing a short name — their projections would overwrite each
  other's file.

[0.2.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.2.0
[0.1.1]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.1.1
[0.1.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.1.0
