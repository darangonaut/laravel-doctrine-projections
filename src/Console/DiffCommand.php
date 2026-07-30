<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Console;

use Darangonaut\DoctrineProjections\Schema\StatementClassifier;
use Darangonaut\DoctrineProjections\Support\Config;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generates a Laravel migration from the difference between the mapping
 * and the database. Entities are the authority; the migration is a record.
 *
 * Needs no doctrine/migrations — SchemaTool can do it alone.
 */
final class DiffCommand extends Command
{
    protected $signature = 'doctrine:diff
                            {--name=doctrine_diff : Migration name}
                            {--dry : Print the SQL, write nothing}
                            {--allow-destructive : Allow statements that irreversibly drop data}';

    protected $description = 'Generate a Laravel migration from the entity/database difference';

    public function handle(EntityManagerInterface $em): int
    {
        // Production metadata cache survives a deploy — without clearing it
        // the diff would compare against yesterday's mapping and report
        // that there is nothing to generate.
        $em->getConfiguration()->getMetadataCache()?->clear();

        $metadata = $em->getMetadataFactory()->getAllMetadata();

        if ($metadata === []) {
            $this->components->error('No entities found.');

            return self::FAILURE;
        }

        $sql = (new SchemaTool($em))->getUpdateSchemaSql($metadata);

        $tables = array_map(static fn ($meta): string => $meta->getTableName(), $metadata);

        $classified = (new StatementClassifier($tables, $this->currentColumns($em, $tables)))
            ->classify($sql);

        if ($classified->fatal !== []) {
            $this->components->error('The diff drops a table no entity maps — the schema filter is not working:');
            foreach ($classified->fatal as $statement) {
                $this->line('  '.$statement);
            }
            $this->newLine();
            $this->line('  Restrict the DBAL schema asset filter to your mapped tables, see the README.');

            return self::FAILURE;
        }

        if ($classified->destructive !== [] && ! $this->option('allow-destructive')) {
            $this->components->error('The diff destroys data and --allow-destructive was not given:');
            foreach ($classified->destructive as $statement) {
                $this->line('  '.$statement);
            }
            $this->newLine();
            $this->line('  If the loss is intended, run again with --allow-destructive.');
            $this->line('  down() is empty, so there is no rollback.');

            return self::FAILURE;
        }

        foreach ($classified->rebuiltTables as $table) {
            // Worth saying out loud — SQLite rebuilds drop and recreate the
            // table, so triggers and views attached to it do not survive —
            // but not worth a prompt, because the rows do.
            $this->components->info(
                sprintf('Rebuilt in place: %s (every column carried across)', $table),
            );
        }

        foreach ($classified->warnings as $statement) {
            $this->components->warn('Review (changes a constraint or type): '.$statement);
        }

        if ($sql === []) {
            $this->components->info('Schema matches the entities, nothing to generate.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Entities', (string) count($metadata));
        $this->components->twoColumnDetail('SQL statements', (string) count($sql));

        if ($this->option('dry')) {
            $this->newLine();
            foreach ($sql as $statement) {
                $this->line('  '.$statement.';');
            }

            return self::SUCCESS;
        }

        $path = $this->write($sql);
        $this->components->info('Generated: '.$path);

        return self::SUCCESS;
    }

    /**
     * The columns each mapped table has right now.
     *
     * A SQLite rebuild cannot be judged from the SQL alone — dropping a
     * column and renaming one produce identical statements — so the
     * classifier is given the current shape of the table to compare
     * against. Tables that do not exist yet are simply absent, and a
     * rebuild is never claimed lossless without this.
     *
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function currentColumns(EntityManagerInterface $em, array $tables): array
    {
        $schema = $em->getConnection()->createSchemaManager();
        $existing = array_map('strtolower', $schema->listTableNames());
        $columns = [];

        foreach ($tables as $table) {
            if (! in_array(strtolower($table), $existing, true)) {
                continue;
            }

            $columns[$table] = array_values(array_map(
                static fn ($column): string => $column->getName(),
                $schema->listTableColumns($table),
            ));
        }

        return $columns;
    }

    /** @param list<string> $sql */
    private function write(array $sql): string
    {
        $option = $this->option('name');
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(is_string($option) ? $option : '')) ?: 'doctrine_diff';
        $dir = Config::string('doctrine-projections.diff.path');
        $path = sprintf('%s/%s_%s.php', rtrim($dir, '/'), date('Y_m_d_His'), $name);

        $statements = implode("\n", array_map(
            static fn (string $s): string => sprintf(
                "        DB::statement(<<<'SQL'\n            %s\n            SQL);",
                $s,
            ),
            $sql,
        ));

        File::ensureDirectoryExists($dir);
        File::put($path, <<<PHP
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            /**
             * Generated by `php artisan doctrine:diff`.
             *
             * Do not edit by hand — the source of truth is the entity mapping.
             * Change it there and generate a new migration.
             */
            return new class extends Migration
            {
                public function up(): void
                {
            {$statements}
                }

                public function down(): void
                {
                    // A Doctrine diff is one-way. Going back means reverting the
                    // entity change and generating a fresh diff.
                }
            };

            PHP);

        return $path;
    }
}
