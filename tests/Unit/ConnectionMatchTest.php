<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Support\ConnectionMatch;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A generated model carries a table name and nothing else, so it reads
 * whichever Laravel connection it is bound to. Doctrine reads wherever
 * the application pointed it. When those are two different databases the
 * projection returns rows that have nothing to do with the entity —
 * measured with two SQLite files, and nothing said a word.
 *
 * The check is deliberately one-sided: it fires only when it can tell the
 * two apart. Staying silent on a setup it cannot read is the whole point,
 * because the `SharedPdoDriver` arrangement this package recommends
 * passes almost no connection parameters, and a false alarm there would
 * be worse than no alarm at all.
 */
final class ConnectionMatchTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function file(string $name): string
    {
        $path = sys_get_temp_dir().'/connection-match-'.getmypid().'-'.$name.'.sqlite';

        touch($path);
        $this->files[] = $path;

        return $path;
    }

    private function doctrine(?string $path = null, ?string $dbname = null): DbalConnection
    {
        return DriverManager::getConnection(array_filter([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'dbname' => $dbname,
        ], static fn (?string $value): bool => $value !== null));
    }

    /** @param array<string, mixed> $config */
    private function laravel(array $config): LaravelConnection
    {
        return new SQLiteConnection(new \PDO('sqlite::memory:'), 'main', '', $config);
    }

    #[Test]
    public function two_different_sqlite_files_are_reported(): void
    {
        $warning = ConnectionMatch::warningFor(
            $this->doctrine(path: $this->file('doctrine')),
            $this->laravel(['database' => $this->file('laravel'), 'name' => 'sqlite']),
        );

        self::assertNotNull($warning);
        self::assertStringContainsString('different databases', $warning);
        self::assertStringContainsString('connection', $warning);
    }

    /**
     * DBAL answers `main` to `getDatabase()` on every SQLite connection,
     * so comparing that instead of the file would have missed the case
     * above entirely.
     */
    #[Test]
    public function the_same_file_is_not_reported(): void
    {
        $shared = $this->file('shared');

        self::assertNull(ConnectionMatch::warningFor(
            $this->doctrine(path: $shared),
            $this->laravel(['database' => $shared]),
        ));
    }

    /** Two ways of naming one file are one file. */
    #[Test]
    public function a_relative_and_an_absolute_path_to_one_file_agree(): void
    {
        $shared = $this->file('relative');
        $relative = basename($shared);

        $previous = getcwd();
        self::assertIsString($previous);

        chdir(dirname($shared));

        try {
            self::assertNull(ConnectionMatch::warningFor(
                $this->doctrine(path: $shared),
                $this->laravel(['database' => $relative]),
            ));
        } finally {
            chdir($previous);
        }
    }

    #[Test]
    public function a_named_database_that_differs_is_reported(): void
    {
        $warning = ConnectionMatch::warningFor(
            $this->doctrine(dbname: 'shop'),
            $this->laravel(['database' => 'analytics']),
        );

        self::assertNotNull($warning);
        self::assertStringContainsString('shop', $warning);
        self::assertStringContainsString('analytics', $warning);
    }

    #[Test]
    public function a_named_database_that_matches_is_not_reported(): void
    {
        self::assertNull(ConnectionMatch::warningFor(
            $this->doctrine(dbname: 'shop'),
            $this->laravel(['database' => 'shop']),
        ));
    }

    /**
     * The recommended SharedPdoDriver setup, and anything else that keeps
     * its parameters to itself: one PDO, no path, nothing to compare.
     * Silence is the correct answer.
     */
    #[Test]
    public function an_unreadable_setup_says_nothing(): void
    {
        self::assertNull(ConnectionMatch::warningFor(
            $this->doctrine(),
            $this->laravel(['database' => 'shop']),
        ));

        self::assertNull(ConnectionMatch::warningFor(
            $this->doctrine(dbname: 'shop'),
            $this->laravel([]),
        ));
    }
}
