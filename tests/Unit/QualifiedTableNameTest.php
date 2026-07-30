<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Support\MappedTables;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `getTableName()` returns the bare name and drops the schema the mapping
 * asked for, so an entity mapped to `archive.entries` produced
 * `$table = 'entries'`. SchemaTool creates `archive.entries`; the
 * projection then reads whichever `entries` the search path finds first —
 * an error at best, a different table with the same name at worst.
 */
final class QualifiedTableNameTest extends TestCase
{
    #[Test]
    public function the_schema_reaches_the_generated_table_name(): void
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Schema'),
            'QualifiedProjections',
        ))->generate();

        self::assertStringContainsString(
            "protected \$table = 'archive.entries';",
            $rendered['Archive']->code,
        );
    }

    /**
     * DBAL names an asset by its schema too, so a filter built from bare
     * names hides a table the mapping owns — and `doctrine:diff` then
     * reads its DDL as touching something nobody maps, which is fatal and
     * cannot be overridden.
     */
    #[Test]
    public function mapped_tables_names_the_schema_as_well(): void
    {
        self::assertSame(
            ['archive.entries'],
            MappedTables::of(EntityManagerFactory::forFixtures('Schema')),
        );
    }

    #[Test]
    public function a_mapping_without_a_schema_is_unchanged(): void
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Rename'),
            'UnqualifiedProjections',
        ))->generate();

        self::assertStringContainsString("protected \$table = 'notes';", $rendered['Note']->code);
        self::assertSame(['notes'], MappedTables::of(EntityManagerFactory::forFixtures('Rename')));
    }
}
