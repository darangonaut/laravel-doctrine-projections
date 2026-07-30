<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Schema\ClassifiedStatements;
use Darangonaut\DoctrineProjections\Schema\StatementClassifier;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The unit tests for the classifier feed it SQL written by hand, which
 * proves the rules but not that DBAL emits what the rules expect. This
 * one runs a real rename through SchemaTool on a real SQLite database and
 * classifies whatever actually comes out.
 *
 * It also checks the rows, because "not classified destructive" would be
 * a poor promise if the data went missing anyway.
 */
final class RenameIsNotDestructiveTest extends TestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = EntityManagerFactory::forFixtures('Rename');

        // The database as it stands before the rename: `content` is what
        // the mapping now calls `body`.
        $this->em->getConnection()->executeStatement(
            'CREATE TABLE notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                title VARCHAR(100) NOT NULL,
                content CLOB NOT NULL
            )',
        );

        $this->em->getConnection()->executeStatement(
            "INSERT INTO notes (title, content) VALUES ('prvá', 'text prvej'), ('druhá', 'text druhej')",
        );
    }

    /** @return list<string> */
    private function diff(): array
    {
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        return (new SchemaTool($this->em))->getUpdateSchemaSql($metadata);
    }

    private function classify(): ClassifiedStatements
    {
        $columns = array_values(array_map(
            static fn ($column): string => $column->getName(),
            $this->em->getConnection()->createSchemaManager()->listTableColumns('notes'),
        ));

        return (new StatementClassifier(['notes'], ['notes' => $columns]))->classify($this->diff());
    }

    #[Test]
    public function dbal_really_does_emit_a_rebuild_for_this(): void
    {
        $sql = $this->diff();

        self::assertNotSame([], $sql, 'the fixture is meant to differ from the table');
        self::assertNotSame(
            [],
            preg_grep('/^\s*DROP\s+TABLE\s+notes/i', $sql),
            'without a DROP TABLE there is nothing for this test to prove',
        );
    }

    #[Test]
    public function a_column_rename_does_not_require_allow_destructive(): void
    {
        $classified = $this->classify();

        self::assertSame([], $classified->fatal);
        self::assertSame([], $classified->destructive);
        self::assertSame(['NOTES'], $classified->rebuiltTables);
    }

    #[Test]
    public function and_the_rows_survive_running_it(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->diff() as $statement) {
            $connection->executeStatement($statement);
        }

        $rows = $connection->fetchAllKeyValue('SELECT title, body FROM notes ORDER BY id');

        self::assertSame(['prvá' => 'text prvej', 'druhá' => 'text druhej'], $rows);
    }

    /**
     * The generated migration wraps itself in a transaction on SQLite, and
     * that is worth nothing unless SQLite really rolls DDL back. It does —
     * but the whole fix rests on it, so it is checked rather than trusted.
     *
     * The failure being simulated is real: tightening a column to NOT NULL
     * while rows hold NULL. Unwrapped, that rejected INSERT lands after the
     * table has already been dropped and recreated, and every row is gone.
     */
    #[Test]
    public function sqlite_rolls_a_failed_rebuild_all_the_way_back(): void
    {
        $connection = $this->em->getConnection();

        $before = $connection->fetchAllAssociative('SELECT id, title, content FROM notes ORDER BY id');
        self::assertCount(2, $before);

        try {
            $connection->transactional(function ($c): void {
                $c->executeStatement('CREATE TEMPORARY TABLE __temp__notes AS SELECT id, title, content FROM notes');
                $c->executeStatement('DROP TABLE notes');
                $c->executeStatement(
                    'CREATE TABLE notes (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        title VARCHAR(100) NOT NULL,
                        content CLOB NOT NULL,
                        added VARCHAR(10) NOT NULL
                    )',
                );
                // nothing supplies the new NOT NULL column: this is the failure
                $c->executeStatement('INSERT INTO notes (id, title, content) SELECT id, title, content FROM __temp__notes');
            });

            self::fail('the rebuild was supposed to fail');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(
            $before,
            $connection->fetchAllAssociative('SELECT id, title, content FROM notes ORDER BY id'),
            'a failed rebuild inside a transaction must leave every row where it was',
        );
    }

    #[Test]
    public function dropping_a_column_still_requires_consent(): void
    {
        // an extra column no entity maps: this rebuild really does lose it
        $this->em->getConnection()->executeStatement('ALTER TABLE notes ADD COLUMN legacy VARCHAR(10)');

        $classified = $this->classify();

        self::assertSame([], $classified->fatal);
        self::assertNotSame([], $classified->destructive, 'a genuine column drop must still be refused');
        self::assertSame([], $classified->rebuiltTables);
    }
}
