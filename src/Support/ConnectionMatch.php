<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use Doctrine\DBAL\Connection as DbalConnection;
use Illuminate\Database\Connection as LaravelConnection;

/**
 * Whether the projections will read the database the entities came from.
 *
 * A generated model carries a table name and nothing else, so it goes to
 * whichever Laravel connection it is bound to — `database.default` unless
 * `connection` is configured. Doctrine is pointed wherever the
 * application pointed it. When those are two different databases the
 * projection reads rows that have nothing to do with the entity, and
 * neither side notices: measured with two SQLite files, the projection
 * returned a row from the other one.
 *
 * Deliberately conservative. Silence here means "could not tell", not
 * "they match" — the recommended `SharedPdoDriver` setup passes only a
 * `dbname`, and a false alarm on the setup this package recommends would
 * be worse than no alarm at all.
 */
final class ConnectionMatch
{
    public static function warningFor(DbalConnection $doctrine, LaravelConnection $laravel): ?string
    {
        $left = self::doctrineDatabase($doctrine);
        $right = self::laravelDatabase($laravel);

        if ($left === null || $right === null || $left === $right) {
            return null;
        }

        return sprintf(
            'Doctrine is on %s and the projections will read %s (Laravel connection "%s"). They are '
            .'different databases, so the models would return rows that have nothing to do with the '
            .'entities. Set `connection` in config/doctrine-projections.php to the Laravel '
            .'connection that holds these tables.',
            $left,
            $right,
            $laravel->getName() ?? 'default',
        );
    }

    /**
     * SQLite is the case worth getting right: DBAL answers `main` to
     * `getDatabase()` whatever file it is on, so the file path is the only
     * thing that distinguishes two of them.
     */
    private static function doctrineDatabase(DbalConnection $connection): ?string
    {
        $params = $connection->getParams();

        if (($params['memory'] ?? false) === true) {
            return ':memory:';
        }

        foreach (['path', 'dbname'] as $key) {
            $value = $params[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return self::normalise($value);
            }
        }

        return null;
    }

    private static function laravelDatabase(LaravelConnection $connection): ?string
    {
        $database = $connection->getConfig('database');

        return is_string($database) && $database !== ''
            ? self::normalise($database)
            : null;
    }

    /**
     * Two paths to one file compare equal — a relative `database.sqlite`
     * against the absolute path DBAL was handed is the same database, and
     * warning about it would be a false alarm.
     */
    private static function normalise(string $database): string
    {
        if ($database === ':memory:') {
            return $database;
        }

        $real = realpath($database);

        return $real === false ? $database : $real;
    }
}
