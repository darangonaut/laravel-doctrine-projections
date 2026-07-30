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
 * The generated migration is named after the timestamp *to the second*
 * plus `--name`, and the default name is `doctrine_diff`. Two plain runs
 * in quick succession therefore resolved to one path, and the second
 * silently replaced the first — losing a migration that may already have
 * been edited.
 */
final class DiffFileSafetyTest extends TestCase
{
    private string $migrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->migrations = sys_get_temp_dir().'/projections-diff-safety-'.getmypid();

        $app['config']->set('doctrine-projections.diff.path', $this->migrations);

        $app->singleton(
            EntityManagerInterface::class,
            static fn (): EntityManagerInterface => EntityManagerFactory::forFixtures('Entities'),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->migrations);

        parent::tearDown();
    }

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        return array_values(array_filter(File::glob($this->migrations.'/*.php'), is_string(...)));
    }

    #[Test]
    public function a_second_run_in_the_same_second_is_refused_rather_than_overwriting(): void
    {
        $this->command('doctrine:diff --name=first')->assertSuccessful();

        $written = $this->migrationFiles();

        self::assertCount(1, $written);

        $original = File::get($written[0]);

        // pretend the migration was edited before the second run
        File::put($written[0], $original."\n// hand edit\n");

        $this->command('doctrine:diff --name=first')->assertFailed();

        self::assertCount(1, $this->migrationFiles(), 'no second file, and no replacement');
        self::assertStringContainsString('// hand edit', File::get($written[0]));
    }

    #[Test]
    public function a_different_name_in_the_same_second_is_fine(): void
    {
        $this->command('doctrine:diff --name=first')->assertSuccessful();
        $this->command('doctrine:diff --name=second')->assertSuccessful();

        self::assertCount(2, $this->migrationFiles());
    }

    #[Test]
    public function a_dry_run_writes_nothing_and_cannot_collide(): void
    {
        $this->command('doctrine:diff --dry')->assertSuccessful();
        $this->command('doctrine:diff --dry')->assertSuccessful();

        self::assertSame([], $this->migrationFiles());
    }
}
