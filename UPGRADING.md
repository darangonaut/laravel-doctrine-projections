# Upgrading

Only the releases that changed behaviour are listed. Everything else is
additive: regenerate and carry on.

The one step that applies to every upgrade:

```bash
php artisan doctrine:projections
```

The generated directory is build output. If you commit it, commit the
result; if you generate on deploy, nothing to do.

## To 0.9 from 0.8

**Check whether your projections read the database you think they do.**
A generated model goes to `database.default` unless told otherwise. If
Doctrine is on a different connection, set `connection` in
`config/doctrine-projections.php` to the Laravel connection that holds
those tables and regenerate — the models then carry `$connection`. The
command now says so when it can tell the two apart; it stays quiet on
setups it cannot read, including `SharedPdoDriver`.

**A composite-key projection now refuses more.** `chunkById()`,
`chunkByIdDesc()`, `eachById()`, `lazyById()`, `lazyByIdDesc()`,
`getRouteKey()` and `resolveRouteBinding()` throw `UnsupportedMapping`
instead of answering, and so does an unordered `chunk()`,
`cursorPaginate()` or `lazy()`. They were not working before — chunking
walked part of the table and returned true, and route binding was a
silent 404. Where you were relying on them, name the column
(`chunkById(100, $fn, 'seat_number')`) or the field
(`resolveRouteBinding($value, 'row_letter')`), or add an explicit
`orderBy()`.

**A projection keyed by a boolean now finds both its rows.** If you had
worked around `find(false)` returning null, the workaround can go.

**`$namespace` is normalised.** A leading or trailing backslash in
`config/doctrine-projections.php` used to generate a parse error in every
file; it is now trimmed. A value that cannot be a namespace at all fails
the command before anything is written, where it used to write
everything and exit 0.

**Regenerate.** The generated files change if you set `connection`, and
`--check` will ask for it anyway.

## To 0.8 from 0.7

**An abstract class under a single-table root with no concrete subclasses
now returns nothing.** It used to return every row in the table, which is
what having no scope at all amounts to. Doctrine answers 0 for the same
question. If you were relying on such a projection to hand back rows, the
class you actually want is the root, or the abstract class's concrete
descendant.

**`--check` and `--dry` no longer clear the application's metadata
cache.** They were doing it as a side effect of reading fresh metadata,
and on a live server that meant emptying a cache shared with every
request in flight. Nothing about the commands' output changes. If your
deploy was relying on `doctrine:projections --check` to clear a stale
APCu metadata cache, clear it explicitly instead — that was never what
the flag meant.

**A regenerate no longer empties the output directory first.** Files are
written and renamed over their targets, and only files whose entity is
gone are deleted. Nothing to change; the difference is that an
application serving requests no longer sees a moment without its models.
A transient `.php.tmp` file exists during the run — if you match on the
directory contents in a build script, match `*.php`.

**The command now warns when the autoloader cannot see what it just
generated.** This fires after `composer dump-autoload
--classmap-authoritative`. Generate before dumping the autoloader and it
does not come up; plain `--optimize` never triggers it.

## To 0.7 from 0.6

**A projection namespace that your entities live in is now refused.** It
gave the generated model the entity's own fully qualified name, so
whichever the autoloader reached first won — a redeclaration fatal, or an
application quietly handed a read-only model where it asked for the
entity. Point `namespace` in `config/doctrine-projections.php` at a
directory of its own.

**Two entities whose short names differ only in case are now a hard
error.** `Order` and `order` are one file on macOS and Windows, so one
projection was overwriting the other.

**`doctrine:diff` refuses to overwrite an existing migration.** The file
name is the timestamp to the second plus `--name`, and the default name
is `doctrine_diff` — two runs in the same second used to resolve to one
path silently. Pass a different `--name`, or wait a second.

**An abstract class in the middle of a single-table hierarchy is now
scoped to its subclasses.** It had no scope at all, so it returned every
row in the table. If you were relying on that to mean "everything", use
the root class — that is what the root is for.

## To 0.6 from 0.5

**`bigint` and `decimal` columns now read back as `string`**, which is
what Doctrine returns for them. They were `int` and `float`, and both lost
digits: on MySQL, `12345678901234.5678` came back as `12345678901234.568`.

Anything comparing these with `===`, or serialising them to JSON where a
number was expected, will see the change. Casting at the call site is the
usual fix:

```php
(int) $invoice->line_count      // bigint
(float) $invoice->total         // decimal, if you can accept the loss
```

**A `time` column now reads back anchored at 1970-01-01**, matching
Doctrine, rather than today's date at that clock time.

## To 0.5.1 from 0.5.0

**`doctrine:projections` refuses to run if the output directory holds PHP
files it did not write.** The directory is emptied on every run, and
`path` is one config value away from `app_path('Models')`. If you
deliberately keep other classes there, move them or point `path`
elsewhere.

## To 0.4 from 0.3

**`getKey()` on a composite-key projection now throws** instead of
returning null. So do `is()`, `unique()`, `diff()`, `contains()`,
`modelKeys()`, `fresh()`, `find()` and `findMany()`.

They were not working before — they were answering. `$a->is($b)` was true
for different rows, `unique()` collapsed a collection to nothing, and
`fresh()` handed back a different row. Compare the key columns yourself:

```php
$seat->row_letter === $other->row_letter
    && $seat->seat_number === $other->seat_number
```

Reading is unaffected: `where()`, `get()`, `pluck()`, casts, ordering and
`toArray()` never touch the key.
