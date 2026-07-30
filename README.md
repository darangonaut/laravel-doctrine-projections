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
| `enumType` | an enum cast |
| non-integer key | `$keyType` + `$incrementing = false` |
| composite key | `$primaryKey = null` **and a warning** — see below |
| single table inheritance | a discriminator global scope on each subclass |

**Composite keys are refused, not guessed.** Eloquent has no support for
them, so rather than silently picking the first column (which would make
`find()` return an arbitrary row) the projection is emitted with
`$primaryKey = null` and the command warns. Reading via `where()` works;
`find()` and `getKey()` do not.

**Single table inheritance is scoped, not ignored.** Every subclass shares
one table, so without a filter `CardPayment::all()` would hand back cash
payments. Each subclass gets a global scope on the discriminator column;
the root class stays unscoped, because "every payment" is a meaningful
query and the root is what represents it.

**Class table inheritance (JOINED) is refused.** The entity spans several
tables and needs a join to reconstruct — an Eloquent model bound to one
table cannot express that, and a projection quietly returning only the root
columns would be worse than none.

Name collisions are handled: an entity called `HasMany`, `Model` or
`ReadOnlyModel` produces fully-qualified references instead of a broken
import. Two entities sharing a short name are a hard error, because their
projections would overwrite each other's file.

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

`DROP TABLE` is not blanket-fatal on purpose: SQLite cannot alter a column
except by rebuilding the table, so DBAL emits it routinely there. What
matters is whether an entity maps the table.

Generated migrations are **raw SQL and therefore driver-specific** — output
generated on MySQL will not run on SQLite.

### Restricting the schema filter

`doctrine:diff` refuses to drop tables no entity maps, but that is a
backstop, not the fix. Doctrine considers every table it can see to be
its own, so without a filter it will propose dropping `users`, `sessions`
and `migrations`. Set this up when you build the EntityManager:

```php
$owned = array_map(
    fn ($meta) => $meta->getTableName(),
    $em->getMetadataFactory()->getAllMetadata(),
);

$em->getConnection()->getConfiguration()->setSchemaAssetsFilter(
    fn (string $table): bool => in_array($table, $owned, true),
);
```

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

## What is not covered

Being explicit about the edges, since a generator that guesses is worse
than one that refuses:

- **Class table inheritance** — refused with an error (see above).
- **Custom Doctrine types** get no cast, so they read back as the raw
  column value. Add a cast by hand in the host app if you need one — but
  remember the directory is regenerated, so it belongs in a subclass or an
  accessor elsewhere, not in the generated file.
- **Embeddables and mapped superclasses** are skipped: they have no table
  of their own.
- **Doctrine filters and second-level cache** do not apply to projections.
  They query the table directly.

## Requirements

PHP 8.3 or 8.4, Doctrine ORM 3.1+, DBAL 4, Laravel 12 or 13.

Laravel 11 is not supported: every 11.x release is currently blocked by
security advisories, so Composer will not install it. All four supported
combinations are covered by CI.

## Testing and analysis

```bash
composer install
vendor/bin/phpunit                    # 52 tests
vendor/bin/phpstan analyse            # level max, no baseline
vendor/bin/pint --test
```

PHPStan runs at **level max with no baseline and no `@phpstan-ignore`
anywhere** — a package whose pitch is type safety has no business
exempting itself. It earned its keep immediately: it found that the
association handling accessed `joinColumns` without narrowing to an
owning side, which is the same shape as the bug that once crashed the
generator on the inverse side of a OneToOne.


66 tests, in three parts:

generation and SQL classification are pure transformations and run without
a database; the lock and the inheritance scope are verified against real
SQLite — the lock by attempting all 23 write paths, the scope by writing
the generated files out, loading them and querying a table that holds rows
of every subclass; and the commands run through a real Laravel application
via Testbench, so the service provider and config are exercised rather than
assumed.

Asserting on emitted strings would have passed just as happily while the
scope did nothing, which is why the middle group exists.

## License

MIT.
