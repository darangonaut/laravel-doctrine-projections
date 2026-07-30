<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Schema\StatementClassifier;
use Darangonaut\DoctrineProjections\Support\MappedTables;
use Darangonaut\DoctrineProjections\Tests\Fixtures\RenameBefore\Note;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Renaming a column on each driver in turn.
 *
 * The package makes driver-specific claims — SQLite rebuilds the table,
 * MySQL emits `CHANGE`, PostgreSQL can roll DDL back — and until this
 * ran on a server none of them had ever executed. The DDL comes from
 * SchemaTool rather than from a string in this file, because the
 * difference between drivers is exactly what is under test.
 */
final class RenameDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('RenameBefore', 'DifferentialRename'.getmypid());

        $note = new Note;
        $note->title = 'prvá';
        $note->content = 'text prvej';

        $second = new Note;
        $second->title = 'druhá';
        $second->content = 'text druhej';

        $this->harness->em()->persist($note);
        $this->harness->em()->persist($second);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @return list<string> the SQL that turns `content` into `body` */
    private function renameSql(): array
    {
        $after = $this->harness->mappingFor('Rename');

        return (new SchemaTool($after))->getUpdateSchemaSql(
            $after->getMetadataFactory()->getAllMetadata(),
        );
    }

    #[Test]
    public function a_rename_is_never_classified_as_data_loss(): void
    {
        $sql = $this->renameSql();

        self::assertNotSame([], $sql, 'the two mappings are meant to differ');

        $after = $this->harness->mappingFor('Rename');
        $schema = $after->getConnection()->createSchemaManager();

        $columns = array_values(array_map(
            static fn ($column): string => $column->getName(),
            $schema->listTableColumns('notes'),
        ));

        $classified = (new StatementClassifier(
            MappedTables::of($after),
            ['notes' => $columns],
        ))->classify($sql);

        self::assertSame([], $classified->fatal, 'the schema filter should hide every other fixture');
        self::assertSame([], $classified->destructive, '--allow-destructive must not be needed for a rename');
    }

    #[Test]
    public function the_rows_survive_the_rename_on_this_driver(): void
    {
        $connection = $this->harness->em()->getConnection();

        foreach ($this->renameSql() as $statement) {
            $connection->executeStatement($statement);
        }

        self::assertSame(
            ['prvá' => 'text prvej', 'druhá' => 'text druhej'],
            $connection->fetchAllKeyValue('SELECT title, body FROM notes ORDER BY id'),
        );
    }

    /**
     * The claim behind wrapping generated migrations in a transaction.
     * MySQL and MariaDB commit implicitly on DDL, so promising atomicity
     * there would be promising something the server will not honour.
     */
    #[Test]
    public function the_platform_matches_what_the_generator_assumes_about_ddl(): void
    {
        $platform = $this->harness->em()->getConnection()->getDatabasePlatform();

        $rollsBackDdl = $platform instanceof SQLitePlatform || $platform instanceof PostgreSQLPlatform;

        self::assertSame(
            Database::driver() !== 'mysql' && Database::driver() !== 'mariadb',
            $rollsBackDdl,
            'the driver under test disagrees with the platform the generator sees',
        );
    }
}
