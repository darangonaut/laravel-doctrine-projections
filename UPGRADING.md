# Upgrading

Only the releases that changed behaviour are listed. Everything else is
additive: regenerate and carry on.

The one step that applies to every upgrade:

```bash
php artisan doctrine:projections
```

The generated directory is build output. If you commit it, commit the
result; if you generate on deploy, nothing to do.

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
