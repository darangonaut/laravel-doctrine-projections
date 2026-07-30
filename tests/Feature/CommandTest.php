<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Feature;

use Darangonaut\DoctrineProjections\ProjectionsServiceProvider;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The commands run through a real Laravel application here, so the service
 * provider, the merged config and the console wiring are exercised rather
 * than assumed. Everything else in the suite calls the generator directly.
 */
final class CommandTest extends TestCase
{
    private string $output;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->output = sys_get_temp_dir().'/projections-feature-'.getmypid();

        $app['config']->set('doctrine-projections.namespace', 'Generated\\Projections');
        $app['config']->set('doctrine-projections.path', $this->output);

        // The package never builds an EntityManager — it uses whatever the
        // application bound. This is that binding.
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

    /**
     * `artisan()` is declared as PendingCommand|int; the assertion helpers
     * only exist on the former. Narrowing once here keeps every test from
     * repeating it.
     */
    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /** @return list<string> */
    private function registeredCommands(): array
    {
        $app = $this->app;

        self::assertNotNull($app);

        return array_keys($app->make(Kernel::class)->all());
    }

    #[Test]
    public function the_provider_registers_the_commands(): void
    {
        $commands = $this->registeredCommands();

        self::assertContains('doctrine:projections', $commands);
        self::assertContains('doctrine:diff', $commands);
    }

    #[Test]
    public function generating_writes_the_projections_to_the_configured_path(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        self::assertFileExists($this->output.'/Account.php');
        self::assertFileExists($this->output.'/Profile.php');

        $code = File::get($this->output.'/Profile.php');
        self::assertStringContainsString('namespace Generated\\Projections;', $code);
        self::assertStringContainsString("\$this->belongsTo(Account::class, 'account_id')", $code);
    }

    #[Test]
    public function dry_run_reports_without_writing(): void
    {
        $this->command('doctrine:projections --dry')->assertSuccessful();

        self::assertDirectoryDoesNotExist($this->output);
    }

    #[Test]
    public function check_fails_when_the_projections_are_missing(): void
    {
        $this->command('doctrine:projections --check')->assertFailed();
    }

    #[Test]
    public function check_passes_right_after_generating(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();
        $this->command('doctrine:projections --check')->assertSuccessful();
    }

    #[Test]
    public function check_fails_once_a_generated_file_is_edited(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        File::append($this->output.'/Account.php', "\n// hand edit\n");

        $this->command('doctrine:projections --check')->assertFailed();
    }

    #[Test]
    public function check_reports_a_projection_whose_entity_is_gone(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        File::put($this->output.'/Orphan.php', "<?php\n");

        $this->command('doctrine:projections --check')->assertFailed();
    }

    #[Test]
    #[WithConfig('doctrine-projections.entities.except', ['*\\Entities\\Profile'])]
    public function the_entity_filter_from_config_is_honoured(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        self::assertFileExists($this->output.'/Account.php');
        self::assertFileDoesNotExist($this->output.'/Profile.php');
    }

    #[Test]
    public function a_failure_leaves_existing_projections_untouched(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();
        $before = File::get($this->output.'/Account.php');

        // two entities sharing a short name is a hard error
        $app = $this->app;
        self::assertNotNull($app);
        $app->singleton(
            EntityManagerInterface::class,
            static fn (): EntityManagerInterface => EntityManagerFactory::forFixtures('Duplicate'),
        );

        $this->command('doctrine:projections')->assertFailed();

        self::assertSame($before, File::get($this->output.'/Account.php'));
    }
}
