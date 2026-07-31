<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------
    | Where generated projections go
    |---------------------------------------------------------------------
    |
    | The directory is wiped on every run — treat it as build output, not
    | as source. Commit it if you want the models browsable on GitHub;
    | gitignore it if you would rather generate on deploy. Either works,
    | as long as `doctrine:projections` runs right after `migrate`.
    |
    */

    'namespace' => 'App\\Models\\Projections',

    'path' => app_path('Models/Projections'),

    /*
    |---------------------------------------------------------------------
    | Which entities get a projection
    |---------------------------------------------------------------------
    |
    | Patterns are matched with fnmatch() against the fully qualified class
    | name. Leave `only` empty to project every mapped entity.
    |
    | A relation pointing at an entity you excluded is skipped, with a
    | warning naming it — a projection cannot reference a class that was
    | never generated.
    |
    */

    /*
    |---------------------------------------------------------------------
    | Which Laravel connection the models read
    |---------------------------------------------------------------------
    |
    | Null means `database.default`, which is right whenever Doctrine and
    | Laravel are on the same database. Name a connection when they are
    | not: a projection carries a table name and nothing else, so without
    | this it reads whichever database Laravel happens to default to — and
    | returns rows belonging to something else entirely, quietly.
    |
    | The command compares the two sides where it can and says so when
    | they differ.
    |
    */

    'connection' => null,

    'entities' => [
        'only' => [],
        'except' => [],
    ],

    /*
    |---------------------------------------------------------------------
    | Migration generation (doctrine:diff)
    |---------------------------------------------------------------------
    |
    | Optional. Turn it off if you already use laravel-doctrine/migrations
    | or write migrations by hand.
    |
    | Note that generated migrations are raw SQL and therefore tied to the
    | driver they were generated on: MySQL output will not run on SQLite.
    |
    */

    'diff' => [
        'enabled' => true,
        'path' => database_path('migrations'),
    ],

];
