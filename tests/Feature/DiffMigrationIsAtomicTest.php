<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Feature;

use Darangonaut\DoctrineProjections\ProjectionsServiceProvider;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Laravel's SQLite grammar reports `supportsSchemaTransactions() === false`,
 * so it runs migrations unwrapped — and on SQLite a column change is a
 * table rebuild. Fail halfway through one and the table has already been
 * dropped and recreated empty.
 *
 * That is not hypothetical: tightening a column to NOT NULL while rows
 * held NULL emptied a table of eight rows. So the generated migration
 * wraps itself where the database can actually roll DDL back.
 */
final class DiffMigrationIsAtomicTest extends TestCase
{
    private string $output;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->output = sys_get_temp_dir().'/projections-diff-'.getmypid();

        $app['config']->set('doctrine-projections.diff.path', $this->output);

        $app->singleton(
            EntityManagerInterface::class,
            static fn (): EntityManagerInterface => EntityManagerFactory::forFixtures('Entities'),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);

        parent::tearDown();
    }

    /** Runs the command and returns the path of the one file it wrote. */
    private function generate(): string
    {
        $pending = $this->artisan('doctrine:diff --name=atomic');

        self::assertInstanceOf(PendingCommand::class, $pending);

        $pending->run();

        $files = File::glob($this->output.'/*_atomic.php');

        self::assertCount(1, $files);
        self::assertIsString($files[0]);

        return $files[0];
    }

    #[Test]
    public function the_generated_migration_wraps_itself_in_a_transaction_on_sqlite(): void
    {
        $code = File::get($this->generate());

        self::assertStringContainsString('DB::transaction(function (): void {', $code);
        self::assertStringContainsString('DB::statement(', $code);
    }

    #[Test]
    public function the_generated_migration_is_valid_php(): void
    {
        // the wrapping rewrites indentation and adds a closure, which is
        // exactly the kind of string assembly that silently emits a syntax
        // error nobody notices until deploy
        $code = File::get($this->generate());

        // TOKEN_PARSE runs the real parser and throws on bad syntax, which
        // beats shelling out to `php -l` — no subprocess, no quoting of a
        // binary path that may contain spaces
        $tokens = token_get_all($code, TOKEN_PARSE);

        self::assertNotSame([], $tokens);
    }

    #[Test]
    public function the_migration_class_still_loads_and_exposes_up_and_down(): void
    {
        $migration = require $this->generate();

        self::assertIsObject($migration);
        self::assertTrue(method_exists($migration, 'up'));
        self::assertTrue(method_exists($migration, 'down'));
    }
}
