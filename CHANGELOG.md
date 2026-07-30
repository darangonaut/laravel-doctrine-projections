# Changelog

All notable changes to this package are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [0.5.1] — 2026-07-30

Another round of invented scenarios. The bugs have moved: these are not
mistakes in reading the mapping any more, but in the places the package
touches Laravel and the filesystem — which is also why the differential
suite could not have found them.

### Fixed

- **A table mapped into its own schema lost it.** `getTableName()` returns
  the bare name, so `#[ORM\Table(name: 'entries', schema: 'archive')]`
  produced `$table = 'entries'` while SchemaTool created
  `archive.entries`. On PostgreSQL the projection would read whichever
  `entries` the search path finds first — an error, or a different table
  with the same name. `MappedTables` had the same gap, which made
  `doctrine:diff` read that table's DDL as touching something nobody maps.

- **The output directory is no longer wiped if it holds anything this
  command did not write.** It is emptied of `*.php` on every run, and
  `path` is one config value away from somewhere that matters —
  `app_path('Models')` instead of `app_path('Models/Projections')` would
  have taken every hand-written model with it. Stale *generated* files are
  still removed; a file without the `GENERATED` header stops the run
  before anything is deleted or written.

  If you deliberately keep other PHP in that directory, this will now
  fail. That is the intent.

- **Restoring a queued projection keeps its discriminator.** Laravel
  restores a serialized model with `newQueryWithoutScopes()` so a
  soft-deleted one can come back; a projection has no such case, and
  dropping the scope meant `CardPayment::find($cashPaymentId)` was null
  while the same id through a job handed back a `CardPayment` holding the
  cash payment's row. Only projections that actually have a discriminator
  scope get the override.

### Verified, unchanged

- `withCount()`, `withSum()`, `has()` and `whereHas()` over a relation
  carrying `#[ORM\OrderBy]` — Laravel drops the ordering when it rewrites
  a relation into a subquery, so the aggregates are correct. Now pinned by
  a test, since 0.3.3 is what put an `ORDER BY` there.
- A self-referencing `ManyToMany` and two associations onto the same
  entity: both keep their sides and keys straight.
- A mapped superclass gets no projection; everything extending it carries
  its columns and casts.
- An integer discriminator, on all three drivers.

## [0.5.0] — 2026-07-30

The release that stops finding bugs by hand.

Every earlier fix came from picking a mapping shape and checking it. That
worked — eight bugs in eleven shapes — but it never converged, because
the next shape nobody thought of was always the one still broken. This
release adds a test that asserts the two sides *agree* rather than
asserting a specific answer, and points it at three real databases.

Two more bugs turned up on the way. Both had the same shape as the rest:
nothing failed, the numbers were just wrong.

### Fixed

- **Single table inheritance scoped a class to its own discriminator
  value, excluding its subclasses.** A `CorporateCardPayment` is a
  `CardPayment`, and Doctrine returns it from `CardPayment` queries.
  Measured on a three-level hierarchy: the entity returned 3 rows, the
  projection returned 1. The scope now covers a class and everything
  below it — `where()` for a leaf, `whereIn()` for a class with children.

### Added

- **A differential test suite.** Doctrine and the generated projections
  run on one connection, and every mapped column, every association and
  its order, and every collection's keys must match. It is written
  against the mapping, so adding an entity to a fixture directory extends
  the coverage without touching a test.

- **CI runs it against SQLite, MySQL 8.4 and PostgreSQL 16.** The package
  was built entirely on SQLite while making driver-specific claims —
  that MySQL implicitly commits DDL, that a `CHANGE` preserves data.
  Those claims had never executed. They do now, and they hold.

- **Three divergences the package cannot remove are now reported at
  generation** instead of living in a list of limitations:
  - `indexBy` on a collection — Doctrine returns a map, an Eloquent
    relation returns a list, so `$config->settings['timezone']` is the
    setting through the entity and null through the projection.
  - A custom Doctrine type — the entity gets `convertToPHPValue()`, the
    projection gets what the column holds.
  - **An enabled Doctrine filter** — the one with teeth. A filter narrows
    entity queries and cannot narrow a projection: with a tenant filter
    on, the entity returned 2 rows and the projection returned all 4.

### Verified, unchanged

- `#[ORM\Version]` is a plain column and is projected correctly,
  including after Doctrine bumps it on flush.
- A table or column named with an SQL reserved word needs nothing
  special: Doctrine strips the backticks its own mapping asks for, and
  Eloquent quotes identifiers itself.

## [0.4.1] — 2026-07-30

Both found by checking what a to-one association onto a composite-key
entity generates. Neither changes behaviour — they stop the generated
file from stating things that are not true.

### Fixed

- **Foreign key columns are documented with the type they point at.** It
  was hardcoded to `int`, so an entity keyed by UUID produced
  `@property int|null $parent_uuid` for a `VARCHAR(36)` — wrong for every
  non-integer key, and static analysis believed it. A unit test asserted
  the wrong type, which is how it survived.

- **An association joining on several columns is skipped, with a
  warning.** Pointing at an entity with a composite key needs two join
  columns; `belongsTo` takes one. The generator emitted
  `belongsTo(Seat::class, 'seat_row')` and dropped the second silently,
  so the relation matched on the row letter alone and returned whichever
  seat in that row came first. Both key columns stay on the model, so the
  join can be written at the call site.

## [0.4.0] — 2026-07-30

A minor rather than a patch: `getKey()` used to return a value and now
throws. Nothing that reads rows is affected, but code comparing
composite-key models by key was getting wrong answers and will now get
an error instead.

### Fixed

- **`getKey()` on a composite-key projection refuses instead of answering
  null.** Eloquent asks it whenever it needs to identify a row and takes
  the answer at face value, so null for every row meant every row looked
  like the same one — silently. `$a->is($b)` was true for different
  seats, `unique()` turned three rows into none, `contains()` found a row
  that was not there, `modelKeys()` gave `[null, null, null]`, and
  `fresh()` on seat B1 handed back **A1**.

  Reading never touches the key, so `where()`, `get()`, `pluck()`, casts,
  ordering and `toArray()` are unaffected.

### Added

- **Columns that shadow an Eloquent `Model` property are reported.** A
  column called `exists` is not readable as `$model->exists`: PHP finds
  Model's own public property and never calls `__get`, so the answer is
  "this row is persisted" while the column says otherwise. The generator
  now warns and omits that column from the docblock, rather than telling
  every IDE and analyser something untrue. `getAttribute('exists')` still
  works, as does `toArray()`.

- **Associations that shadow a `Model` method are refused.** A method
  declared on the class silently replaces the one inherited from a trait,
  so an association named `delete` would have swapped the write lock for
  a relation with no warning from PHP at all.

  Words reserved by *SQL* need none of this: Doctrine strips the
  backticks its mapping asks for, and Eloquent quotes identifiers itself,
  so a table called `order` and a column called `key` were already fine.
  Now covered by tests.

## [0.3.3] — 2026-07-30

### Fixed

- **`#[ORM\OrderBy]` is now carried onto the relation.** It was dropped,
  so an association came back sorted through the entity and unsorted
  through the projection. On a list whose insertion order was the reverse
  of its `position`, the two sides returned the same rows in opposite
  order — nothing threw, the sequence was simply wrong.

  Doctrine keys `orderBy` by *field* name, so `dueOn` becomes `due_on`
  here; emitting the field name would ask the database for a column that
  does not exist. A field that cannot be resolved to a column raises
  `UnsupportedMapping` rather than generating a relation that silently
  ignores half its mapping.

## [0.3.2] — 2026-07-30

Found while checking that tightening a foreign key from nullable to
required behaves — it does. This turned up on the way.

### Fixed

- **Multi-word relations were documented under a name that does not
  exist.** The `@property` line was snake_cased while the generated method
  is camelCase, so a `blockedBy` association was advertised as
  `$task->blocked_by` — which Eloquent resolves to null, silently, while
  the docblock and every IDE insist it is the related model.
  `with('blocked_by')` threw `Call to undefined relationship`.

  Single-word relations hid it: `parent` and `tags` are identical in both
  spellings, and every fixture had only those. The foreign key column
  beside it stays snake_case, because that one really is a column.

## [0.3.1] — 2026-07-30

Both fixes came out of walking through the mapping shapes the README
calls edge cases and checking that they behave as documented. Embeddables
did; composite keys did not.

### Fixed

- **`delete()` on a composite-key projection now throws
  `ReadOnlyProjection`** instead of Laravel's
  `LogicException: No primary key defined on model`. Laravel checks for a
  primary key *before* firing the `deleting` event the lock hangs off, so
  the write was refused for the wrong reason and with an exception the
  package does not document. The trait overrides `delete()` directly, so
  every projection now refuses the same way whatever its key looks like.

- **`find()` and `findMany()` on a composite-key projection explain
  themselves.** With no `$primaryKey` they composed `where seats. = 1`
  and returned `no such column: seats.`, plus a PHP deprecation raised
  inside Eloquent. They now throw `UnsupportedMapping` naming the model
  and pointing at `->where([...])`.

### Documented

- Embeddables were listed only as "skipped". They get no projection of
  their own, but their columns appear on the embedding entity under their
  column names — `billing_street`, or bare `street` with
  `columnPrefix: false`. Now covered by tests, since Doctrine calls that
  field `billing.street` and emitting *that* would produce a property
  nobody can use.

## [0.3.0] — 2026-07-30

Everything here came out of walking through schema changes one at a time
and checking what happens when each one *fails*, rather than when it
succeeds. Two of the four scenarios tried turned up bugs.

### Added

- `Support\MappedTables` — every table the mapping owns, join tables
  included. The README previously showed a hand-rolled
  `array_map(getTableName(), ...)` for the DBAL schema asset filter, which
  silently omits join tables: Doctrine then cannot see a table it owns,
  and `doctrine:diff` asks for `--allow-destructive` on a join-table
  rebuild that loses nothing.

### Fixed

- **A legitimate join-table rebuild is no longer reported as a broken
  schema filter.** The classifier's owned-table list came from
  `getTableName()`, which has no entry for a join table, so
  `DROP TABLE task_tag` looked like dropping a table nobody maps — fatal,
  and not overridable by any flag. Renaming the table of a joined entity
  was enough to hit it, and the error blamed the wrong thing.

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

### Documented

- Renaming a *table* is not something the diff can do: Doctrine detects a
  renamed column and carries the data across, but for a table it creates
  the new one empty and leaves the old one behind. Write that one by hand.
- Deleting an entity leaves its table in place. The schema filter stops
  showing it, so the diff has nothing to say — deliberate, since refusing
  to drop unmapped tables is what catches a broken filter.
  `doctrine:projections --check` still reports the orphaned model.

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

[0.5.1]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.5.1
[0.5.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.5.0
[0.4.1]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.4.1
[0.4.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.4.0
[0.3.3]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.3.3
[0.3.2]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.3.2
[0.3.1]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.3.1
[0.3.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.3.0
[0.2.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.2.0
[0.1.1]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.1.1
[0.1.0]: https://github.com/darangonaut/laravel-doctrine-projections/releases/tag/v0.1.0
