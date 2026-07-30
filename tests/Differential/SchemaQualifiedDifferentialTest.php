<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Schema\Archive;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A table in a schema of its own, read through both sides.
 *
 * SQLite has no schemas — `archive.entries` there means an attached
 * database — so this only runs where the idea exists: PostgreSQL, and
 * MySQL where a schema is a database.
 */
final class SchemaQualifiedDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        if (Database::driver() === 'sqlite') {
            self::markTestSkipped('SQLite has no schemas; `archive.entries` would mean an attached database.');
        }

        $this->createSchema();

        $this->harness = Harness::for('Schema', 'DifferentialSchema'.getmypid());

        foreach (['prvý záznam', 'druhý záznam'] as $label) {
            $entry = new Archive;
            $entry->label = $label;

            $this->harness->em()->persist($entry);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /**
     * The schema has to exist before SchemaTool creates a table in it, and
     * the statement differs: PostgreSQL has schemas, MySQL calls the same
     * thing a database.
     */
    private function createSchema(): void
    {
        $config = Database::laravelConfig();
        $capsule = new Manager;
        $capsule->addConnection($config);

        $statement = Database::driver() === 'pgsql'
            ? 'CREATE SCHEMA IF NOT EXISTS archive'
            : 'CREATE DATABASE IF NOT EXISTS archive';

        $capsule->getConnection()->statement($statement);
    }

    #[Test]
    public function the_projection_reads_the_table_in_its_own_schema(): void
    {
        $projection = $this->harness->projection('Archive');

        self::assertSame('archive.entries', (new $projection)->getTable());
        self::assertSame(2, $projection::query()->count());
    }

    #[Test]
    public function both_sides_agree(): void
    {
        (new Compare($this->harness))->columns(Archive::class);
    }
}
