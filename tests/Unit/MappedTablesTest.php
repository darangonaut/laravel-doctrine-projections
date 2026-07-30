<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Support\MappedTables;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The whole reason this helper exists is the join table, so that is what
 * the tests are about.
 */
final class MappedTablesTest extends TestCase
{
    #[Test]
    public function it_lists_join_tables_alongside_entity_tables(): void
    {
        $tables = MappedTables::of(EntityManagerFactory::forFixtures('JoinTable'));

        self::assertContains('books', $tables);
        self::assertContains('genres', $tables);

        // the one `getTableName()` alone would miss
        self::assertContains('book_genre', $tables);
    }

    #[Test]
    public function the_join_table_is_listed_once_not_once_per_side(): void
    {
        // both sides of the ManyToMany are mapped; only the owning side
        // carries the join table, so it must not appear twice
        $tables = MappedTables::of(EntityManagerFactory::forFixtures('JoinTable'));

        self::assertSame(array_values(array_unique($tables)), $tables);
        self::assertCount(3, $tables);
    }

    #[Test]
    public function a_mapping_without_join_tables_yields_only_entity_tables(): void
    {
        $tables = MappedTables::of(EntityManagerFactory::forFixtures('Rename'));

        self::assertSame(['notes'], $tables);
    }
}
