# laravel-doctrine-projections

Read-only Eloquent models, generated from your Doctrine ORM mapping.

Write through your domain entities, where the invariants live. Query with
Eloquent, where pagination, `with()`, `withCount()` and Filament tables
already work. The projections are generated, so they cannot drift from the
schema — and they refuse every write, so nobody can slip past the domain.

```php
// writing — Doctrine entity, invariants enforced
$book->publish();
$em->flush();

// reading — Eloquent projection, everything you like about Eloquent
Book::with('author')
    ->whereHas('genres', fn ($q) => $q->where('slug', 'novel'))
    ->paginate(20);

// and this throws ReadOnlyProjection
Book::query()->update(['status' => 'published']);
```

## A working example

[**darangonaut/doctrine-projections-todo**](https://github.com/darangonaut/doctrine-projections-todo)
is a small todo application built on this package: three Doctrine
entities, from which the migration, the schema and the Eloquent models
are all generated.

It is worth a look for two things this README can only assert. Its domain
tests run without booting Laravel or touching a database, because the
entities do not know one exists. And its suite runs on `:memory:`, which
is the sharpest test of `SharedPdoDriver` there is — connection
parameters derived from config would hand Doctrine its own empty
database, and every other test would fail.

It was written against the published package rather than a path
repository, so it also checks that the release installs and works from
nothing.

## What this is not

It is **not** a Doctrine bridge for Laravel. It does not build an
EntityManager, register a connection, or wire authentication — it resolves
whatever the container already has bound to `EntityManagerInterface`. For
the bridge itself use [`laravel-doctrine/orm`](https://github.com/laravel-doctrine/orm)
or your own wiring.

It is one idea, kept small: *generate read-only Eloquent models from
Doctrine metadata*.

## Installation

```bash
composer require darangonaut/laravel-doctrine-projections
php artisan vendor:publish --tag=doctrine-projections-config
```

The package needs `EntityManagerInterface` resolvable from the container.
If you already use `laravel-doctrine/orm`, you are done.

**One EntityManager.** Whatever the container returns for
`EntityManagerInterface` is what gets projected. An application running
two of them will need to bind the one it wants before the command runs —
there is no option to name a manager, because a package that generates
one directory of models has no way to keep two mappings apart in it.

**With `config:cache`**, `path` is resolved when the cache is written.
That is correct for the usual deploy order (cache inside the release
directory) and wrong if you cache in one directory and run in another —
the same as any package using `app_path()` in its config.

## Usage

```bash
php artisan doctrine:projections          # generate
php artisan doctrine:projections --dry    # render and report, write nothing
php artisan doctrine:projections --check  # CI: fail if regenerating would change anything
```

`--check` is the one worth wiring into CI. The failure it catches is a
deploy where someone changed an entity and forgot to regenerate — the
projection then silently lacks the new column. It also reports orphaned
files whose entity is gone.

Configure where they land in `config/doctrine-projections.php`:

```php
'namespace' => 'App\\Models\\Projections',
'path'      => app_path('Models/Projections'),
```

### Choosing which entities to project

```php
'entities' => [
    'only'   => [],                          // empty means all of them
    'except' => ['App\\Entity\\Legacy\\*'],
],
```

Patterns are matched with `fnmatch()` against the fully qualified class
name. A relation pointing at an entity you excluded is **skipped with a
warning naming it** — a projection cannot reference a class that was never
generated, and emitting one anyway would produce a file that fatals on
first use.

The output directory is wiped on every run — treat it as build output.
It has to be a directory of its own: the command refuses to run if it
finds PHP files it did not write, because `app_path('Models')` instead of
`app_path('Models/Projections')` is one character away and would take
every hand-written model with it.
Commit it if you want the models browsable, or gitignore it and generate on
deploy. Either works, as long as generation runs **right after `migrate`**:
a projection that does not know about a new column is worse than no
projection.

Everything is rendered into memory first and the directory is touched only
once every model succeeded, so a failure halfway through never leaves the
application without models.

## What the generator reads from the mapping

Foreign keys, join tables and key types come from Doctrine metadata — they
are never guessed from Laravel naming conventions, because a convention
holds only until someone names a column differently.

| Doctrine | Generated |
|---|---|
| `ManyToOne` | `belongsTo(Author::class, 'author_id')` |
| `OneToMany` | `hasMany(Book::class, 'author_id')` — FK resolved via `mappedBy` |
| `OneToOne` owning | `belongsTo(...)` |
| `OneToOne` inverse | `hasOne(...)` |
| `ManyToMany` owning | `belongsToMany(Genre::class, 'book_genre', 'book_id', 'genre_id')` |
| `ManyToMany` inverse | same table, keys swapped |
| to-one across several join columns | **skipped with a warning** — see below |
| `#[ORM\OrderBy]` | `->orderBy(...)` chained onto the relation, field names resolved to columns |
| `enumType` | an enum cast |
| `bigint`, `decimal` | `'string'` — Doctrine returns one, and an int or float loses digits |
| `time` | `Casts\TimeOfDay` — anchored at the epoch, as Doctrine anchors it |
| `simple_array` | `Casts\SimpleArray` — Laravel's `array` cast is JSON |
| non-integer key | `$keyType` + `$incrementing = false` |
| composite key | `$primaryKey = null` **and a warning** — see below |
| single table inheritance | a discriminator global scope on each subclass |
| `#[ORM\Table(schema: …)]` | `$table = 'archive.entries'`, not the bare name |

**Composite keys are refused, not guessed.** Eloquent has no support for
them, so rather than silently picking the first column (which would make
`find()` return an arbitrary row) the projection is emitted with
`$primaryKey = null` and the command warns.

**An association pointing at one is skipped.** It needs two join columns
and `belongsTo` takes one, so the generated relation matched on the first
and ignored the rest — returning whichever row happened to share it. Both
key columns stay on the model, documented with the types they point at,
so the join can be written at the call site.

Everything that does not need a single key column keeps working —
`where()`, `count()`, `pluck()`, casts, eager loading:

```php
Seat::query()->where(['row_letter' => 'A', 'seat_number' => 2])->first();
```

`find()`, `findMany()` and **anything that identifies a row by its key**
refuse with an explanation: `getKey()`, `is()`, `unique()`, `diff()`,
`contains()`, `modelKeys()`, `fresh()`.

That last group used to answer rather than refuse, and the answers were
wrong without saying so. `getKey()` returned null for every row, and
Eloquent takes that at face value: `$a->is($b)` was true for different
seats, `unique()` turned three rows into none, and `fresh()` on seat B1
handed back A1.

**Single table inheritance is scoped, not ignored.** Every subclass shares
one table, so without a filter `CardPayment::all()` would hand back cash
payments. Each subclass gets a global scope on the discriminator column;
the root class stays unscoped, because "every payment" is a meaningful
query and the root is what represents it.

The scope covers a class **and everything below it**. A
`CorporateCardPayment` is a `CardPayment`, and Doctrine returns it from
`CardPayment` queries — scoping to the class's own value alone is right
for a leaf and an undercount for anything with children.

A scoped projection also overrides `newQueryForRestoration()`. Laravel
restores a queued model without scopes, so a soft-deleted one can come
back; a projection has no such case, and dropping the discriminator meant
`find()` and a queued job gave different answers for the same id.

**Class table inheritance (JOINED) is refused.** The entity spans several
tables and needs a join to reconstruct — an Eloquent model bound to one
table cannot express that, and a projection quietly returning only the root
columns would be worse than none.

Name collisions are handled: an entity called `HasMany`, `Model` or
`ReadOnlyModel` produces fully-qualified references instead of a broken
import. Two entities sharing a short name are a hard error, because their
projections would overwrite each other's file.

### Reserved names

A word reserved by **SQL** is a non-event. Doctrine wants it backticked
in the mapping (`#[ORM\Table(name: '`order`')]`) but hands the name back
clean, and Eloquent quotes identifiers itself — a table called `order`
and a column called `key` both just work.

A name reserved by **Eloquent** is the dangerous one, and there are two
kinds:

- **A column named after a Model property** — `exists`, `timestamps`,
  `incrementing` — cannot be read as `$model->exists`. PHP finds Model's
  own public property and never calls `__get`, so the answer is the
  framework's, not the column's, and nothing errors. The generator warns
  and leaves that column out of the docblock rather than telling every
  IDE something untrue. Read it with `getAttribute('exists')`.
- **An association named after a Model method** — `delete`, `save`,
  `query`, `with` — is refused outright. A method on the class silently
  replaces the one inherited from a trait, so a relation called `delete`
  would quietly remove the write lock while the projection went on
  looking read-only.

## The lock

Three layers, because each covers what the others miss:

| Layer | Covers |
|---|---|
| model events | `save()`, `update()`, `delete()`, `create()`, `firstOrCreate()` |
| `ReadOnlyBuilder` | `query()->update()`, `insert()`, `upsert()`, `increment()`, `truncate()`, `touch()` |
| `ReadOnlyBelongsToMany` | `attach()`, `detach()`, `sync()`, `toggle()` |

Model events alone are not enough, and this is not theoretical — both extra
layers exist because a write got through in testing. `touch()` is the
sharpest example: it writes via `$this->toBase()->update()`, so overriding
`update()` does not catch it.

Because the blocklist is hand-maintained, `ReadOnlyBuilderCoverageTest`
asserts that every write method on `Eloquent\Builder` is overridden and
flags new write-shaped methods after a Laravel upgrade. That test is how
`incrementOrCreate()` was found.

**Deliberate boundary:** `DB::table('books')->update()` cannot be blocked
from here, and neither can raw SQL. The promise is *"you cannot write
through the model"*, not *"you cannot write to the table"* — the same
boundary Doctrine has.

## Migrations from the mapping (optional)

```bash
php artisan doctrine:diff --dry
php artisan doctrine:diff --name=add_subtitle
```

Generates a Laravel migration from the difference between your mapping and
the database, using `SchemaTool` — no `doctrine/migrations` needed. Turn it
off in the config if you already use something else.

Every statement is classified:

| Class | Example | Behaviour |
|---|---|---|
| fatal | `DROP TABLE` on an **unmapped** table, `DROP DATABASE` | always refused — your schema filter is broken |
| destructive | `ALTER … DROP <column>`, `TRUNCATE`, `DROP TABLE` on a mapped table | needs `--allow-destructive`; `down()` is empty, so there is no rollback |
| warning | `DROP INDEX`, `DROP FOREIGN KEY`, `CHANGE`/`MODIFY`, pgsql `DROP NOT NULL` | passes, printed for review |
| clean | a SQLite rebuild that carries every existing column across | passes, one line saying which table was rebuilt |

`DROP TABLE` is not blanket-fatal on purpose: SQLite cannot alter a column
except by rebuilding the table, so DBAL emits it routinely there. What
matters is whether an entity maps the table.

### Renaming a column on SQLite

SQLite has no `ALTER COLUMN`, so renaming one means rebuilding the table:
park the rows in a scratch table, drop, recreate, put them back. Read one
statement at a time that is indistinguishable from total loss, which is
why a rename used to demand `--allow-destructive` for a migration that
loses nothing.

It no longer does. Before deciding, the command reads the columns the
table actually has and checks that the rebuild parks every one of them:

```
INFO  Rebuilt in place: tasks (every column carried across)
```

Drop a column and one is missing from that list, so the prompt comes back.

The comparison is deliberately **not** between what the rebuild saves and
what it restores — those always match, because DBAL parks exactly what it
means to carry. A dropped column simply never appears in the SQL at all,
making a drop and a rename textually identical. Only the live table tells
them apart, so without that information nothing is called lossless.

One thing a rebuild does not preserve, whatever the classifier says: it
drops and recreates the table, so triggers and views attached to it are
gone afterwards. That is SQLite, not this package.

### Generated migrations are atomic where the database allows it

A rebuild that fails halfway through is the dangerous case: the table has
already been dropped and recreated, and the `INSERT` that would have put
the rows back never runs. Laravel does not protect you here — its SQLite
grammar reports `supportsSchemaTransactions() === false`, so migrations
run unwrapped.

That is not theoretical. Tightening a column to `NOT NULL` while rows
still held `NULL` emptied a table of eight rows: the rejected `INSERT`
landed after the drop, and the migration was not even recorded as run.

So on databases that can roll DDL back — SQLite and PostgreSQL — the
generated migration wraps itself:

```php
public function up(): void
{
    DB::transaction(function (): void {
        DB::statement(<<<'SQL'
            ...
```

The same failure now leaves every row where it was. MySQL and MariaDB
implicitly commit on DDL, so nothing is wrapped there — promising an
atomicity the server will not honour would be worse than being plain
about it.

**Check your data before tightening a constraint.** Adding `NOT NULL`,
`UNIQUE` or a narrower type is valid DDL that a populated table can
reject at runtime; the diff cannot see that coming, and on MySQL there is
no rollback to save you.

### Two changes the diff cannot make for you

**Renaming a table.** Doctrine detects a renamed *column* and carries the
data over; it does not do the same for a table. The diff creates the new
one empty and leaves the old one sitting there with every row still in
it — and if a join table points at the renamed entity, the migration then
fails on a foreign key. Write that migration by hand.

**Deleting an entity.** Its table is no longer mapped, so the schema
filter hides it and the diff reports nothing to do — the table stays,
with its rows, indefinitely. That is deliberate: refusing to drop tables
it does not map is the same rule that catches a broken filter. Drop it
yourself when you are sure.

`doctrine:projections --check` does catch the model left behind:

```
ERROR  Projections do not match the mapping:
       Label — orphaned, no entity maps to it
```

Generated migrations are **raw SQL and therefore driver-specific** — output
generated on MySQL will not run on SQLite.

The file name is the timestamp to the second plus `--name`, so a second
run inside the same second with the same name is refused rather than
replacing the first.

`doctrine:projections` needs no database at all — it reads mapping, not
schema. `doctrine:diff` does, since it compares against what is there.

### Restricting the schema filter

`doctrine:diff` refuses to drop tables no entity maps, but that is a
backstop, not the fix. Doctrine considers every table it can see to be
its own, so without a filter it will propose dropping `users`, `sessions`
and `migrations`. Set this up when you build the EntityManager:

```php
use Darangonaut\DoctrineProjections\Support\MappedTables;

$owned = MappedTables::of($em);

$em->getConnection()->getConfiguration()->setSchemaAssetsFilter(
    static fn (string $table): bool => in_array($table, $owned, true),
);
```

Use `MappedTables` rather than mapping `getTableName()` over the metadata
yourself. **A join table has no entity, so the obvious one-liner leaves it
out** — and Doctrine then cannot see a table it owns. `doctrine:diff` can
no longer tell whether a rebuild of that join table keeps its rows, so it
asks for `--allow-destructive` on a migration that loses nothing. Nothing
reveals this until something touches a join table; renaming the table of a
joined entity is enough.

## Optional: one connection for both sides

`Support\SharedPdoDriver` lets Doctrine run on the very PDO instance
Eloquent uses. Nothing wires it for you — it is there because deriving
connection parameters from `config('database.connections.*')` looks safe
and is not: `DB_URL`, `unix_socket`, sqlite `:memory:` and
`foreign_key_constraints` are all ways Laravel's real connection differs
from the plain config keys, and each one sends the two sides to different
databases.

```php
use Darangonaut\DoctrineProjections\Support\SharedPdoDriver;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;

$laravel = app('db')->connection();

$dbal = new \Doctrine\DBAL\Connection(
    ['dbname' => $laravel->getDatabaseName()],
    new SharedPdoDriver(new MySQLDriver, $laravel->getPdo()),
    $ormConfig,
);
```

A welcome side effect: it is one connection, so `DB::transaction()` wraps
`$em->flush()` too. `SharedPdoConnection` handles the transaction overlap —
if one is already open, Doctrine borrows it rather than starting its own.

### Doctrine filters do not apply — and that one can bite

A filter narrows entity queries. A projection reads the table, so it does
not narrow at all. With a tenant filter enabled, measured on four rows:

| | rows |
|---|---|
| `$em->getRepository(Note::class)->findAll()` | 2 |
| `Note::query()->count()` on the projection | **4** |

The two rows belonging to the other tenant come back. This is not a bug
to be fixed — Eloquent cannot know about Doctrine's filter registry —
but it is worth stating plainly, because the usual reason for a filter is
exactly the thing that goes wrong here.

If a filter is enabled while generating, the command says so. Otherwise:
apply the same condition at the call site, exclude those entities from
generation, or accept that the projection sees every row.

### Under `Model::shouldBeStrict()`

Generated projections run fine under all three guards, and the write lock
still wins: `delete()` reports being read-only, not a strict-mode
violation.

One thing worth knowing before you conclude a projection is exempt:
Laravel arms the lazy-loading guard only when it hydrates **more than one
row** (`Builder::hydrate()`), so a relation reached from a single
`first()` will not be flagged.

## What is not covered

Being explicit about the edges, since a generator that guesses is worse
than one that refuses:

- **Class table inheritance** — refused with an error (see above).
- **Custom Doctrine types** get no cast, so they read back as the raw
  column value: the entity hands back whatever `convertToPHPValue()` made
  of it, the projection hands back what the column holds. Generation warns
  and the docblock says `string` rather than promising the value object,
  so the difference is visible to static analysis. Add a cast by hand in
  the host app if you need one — but remember the directory is
  regenerated, so it belongs in a subclass or an accessor elsewhere, not
  in the generated file.
- **Mapped superclasses** are skipped: they have no table of their own.
- **Embeddables** get no projection of their own either, but their
  columns do appear on whatever embeds them, under their column names —
  `billing_street`, or bare `street` when `columnPrefix: false`. Doctrine
  calls that field `billing.street`; a generated property of that name
  would be unusable, so the column name is what is emitted.
- **Second-level cache** does not apply. Projections query the table.
- **`indexBy` on a collection** cannot be carried across. Doctrine hands
  back a map keyed by the field; an Eloquent relation is always a list,
  with no hook to change that which survives regeneration. So
  `$config->settings['timezone']` is the setting through the entity and
  null through the projection. Generation warns and tells you the column
  to `keyBy()` at the call site.

## Requirements

PHP 8.3 or 8.4, Doctrine ORM 3.1+, DBAL 4, Laravel 12 or 13.

Laravel 11 is not supported: every 11.x release is currently blocked by
security advisories, so Composer will not install it. All four supported
combinations are covered by CI.

## Testing and analysis

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse            # level max, no baseline
vendor/bin/pint --test
```

PHPStan runs at **level max with no baseline and no `@phpstan-ignore`
anywhere** — a package whose pitch is type safety has no business
exempting itself. It earned its keep immediately: it found that the
association handling accessed `joinColumns` without narrowing to an
owning side, which is the same shape as the bug that once crashed the
generator on the inverse side of a OneToOne.


The suite has four parts.

**Unit** — generation and SQL classification are pure transformations and
run without a database.

**Integration** — the lock and the inheritance scope against real SQLite:
the lock by attempting all 23 write paths, the scope by writing the
generated files out, loading them and querying a table that holds rows of
every subclass. Asserting on emitted strings would have passed just as
happily while the scope did nothing.

**Feature** — the commands through a real Laravel application via
Testbench, so the service provider and config are exercised rather than
assumed.

**Differential** — Doctrine and the generated projections on one
connection, answering the same questions. It asserts they *agree* rather
than asserting a specific answer, which is the difference that matters:
every bug this package has had was a plausible answer that happened not
to be Doctrine's. Every mapped column, every association and its order,
and every collection's keys are compared.

```bash
DIFFERENTIAL_DRIVER=mysql vendor/bin/phpunit --testsuite=differential
```

CI runs that suite against SQLite, MySQL 8.4 and PostgreSQL 16. It is
written against the mapping, so adding an entity to a fixture directory
extends the coverage without touching a test.

## License

MIT.
