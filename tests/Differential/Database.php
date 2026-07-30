<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Doctrine\DBAL\Driver as DbalDriver;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Which database the differential suite runs against.
 *
 * Everything in this package was developed and tested on SQLite, while
 * the README makes driver-specific promises — that MySQL implicitly
 * commits DDL, that PostgreSQL can roll it back. Those claims had never
 * executed. Pointing the same suite at a real server is how they stop
 * being claims.
 *
 * Defaults to SQLite so a plain `vendor/bin/phpunit` needs no setup; CI
 * runs it again per driver.
 */
final class Database
{
    public static function driver(): string
    {
        $driver = getenv('DIFFERENTIAL_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : 'sqlite';
    }

    /** @return array<string, mixed> */
    public static function laravelConfig(): array
    {
        return match (self::driver()) {
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'mysql', 'mariadb' => [
                'driver' => 'mysql',
                'host' => self::env('DIFFERENTIAL_HOST', '127.0.0.1'),
                'port' => self::env('DIFFERENTIAL_PORT', '3306'),
                'database' => self::env('DIFFERENTIAL_DATABASE', 'projections'),
                'username' => self::env('DIFFERENTIAL_USERNAME', 'root'),
                'password' => self::env('DIFFERENTIAL_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => self::env('DIFFERENTIAL_HOST', '127.0.0.1'),
                'port' => self::env('DIFFERENTIAL_PORT', '5432'),
                'database' => self::env('DIFFERENTIAL_DATABASE', 'projections'),
                'username' => self::env('DIFFERENTIAL_USERNAME', 'postgres'),
                'password' => self::env('DIFFERENTIAL_PASSWORD', ''),
                'charset' => 'utf8',
            ],
            default => throw new RuntimeException('Unsupported differential driver: '.self::driver()),
        };
    }

    public static function dbalDriver(): DbalDriver
    {
        return match (self::driver()) {
            'sqlite' => new SQLiteDriver,
            'mysql', 'mariadb' => new MySQLDriver,
            'pgsql' => new PgSQLDriver,
            default => throw new RuntimeException('Unsupported differential driver: '.self::driver()),
        };
    }

    /**
     * SQLite is always there. A server that is configured but not
     * reachable is reported rather than skipped — a silently skipped
     * driver in CI is the same as not testing it.
     */
    public static function unavailableReason(PDO $pdo): ?string
    {
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            return sprintf('%s is not reachable: %s', self::driver(), $e->getMessage());
        }

        // The one failure this suite could not see from its own output:
        // a green run that quietly used a different database than the one
        // it claims to cover. The connection is asked what it actually is.
        $name = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $actual = is_string($name) ? $name : 'unknown';
        $expected = match (self::driver()) {
            'mariadb' => 'mysql',
            default => self::driver(),
        };

        if ($actual !== $expected) {
            return sprintf('asked for %s but connected to %s', $expected, $actual);
        }

        return null;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
