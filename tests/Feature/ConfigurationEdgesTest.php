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
 * Config values that are one keystroke from the right ones.
 *
 * The namespace cases are the reason this file exists: `'\App\Models\Projections'`
 * is how the same name is written almost everywhere else in PHP, and it
 * produced `namespace \App\Models\Projections;` in every generated file —
 * a parse error, after a run that reported success and wrote everything.
 * Nothing failed until the application autoloaded one.
 */
final class ConfigurationEdgesTest extends TestCase
{
    private string $output;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->output = sys_get_temp_dir().'/projections-config-'.getmypid();

        $app['config']->set('doctrine-projections.namespace', 'Generated\\Config');
        $app['config']->set('doctrine-projections.path', $this->output);

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

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function generatedFile(): string
    {
        $this->command('doctrine:projections')->assertSuccessful();

        return File::get($this->output.'/Account.php');
    }

    /** @return list<string> */
    private static function namespaces(): array
    {
        return [
            'Generated\\Config',
            '\\Generated\\Config',
            'Generated\\Config\\',
            '\\Generated\\Config\\',
        ];
    }

    #[Test]
    public function a_namespace_with_stray_backslashes_still_produces_parsable_code(): void
    {
        foreach (self::namespaces() as $namespace) {
            config()->set('doctrine-projections.namespace', $namespace);

            $code = $this->generatedFile();

            self::assertStringContainsString(
                "namespace Generated\\Config;\n",
                $code,
                'from config value '.$namespace,
            );

            $file = $this->output.'/probe.php';
            File::put($file, $code);

            $out = [];
            $status = 0;
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $status);
            File::delete($file);

            self::assertSame(0, $status, implode("\n", $out).' — from config value '.$namespace);
        }
    }

    #[Test]
    public function a_namespace_that_cannot_be_one_is_refused_before_anything_is_written(): void
    {
        config()->set('doctrine-projections.namespace', 'Generated Config');

        $this->command('doctrine:projections')->assertFailed();

        self::assertFileDoesNotExist($this->output.'/Account.php');
    }

    #[Test]
    public function an_empty_namespace_is_refused(): void
    {
        config()->set('doctrine-projections.namespace', '\\');

        $this->command('doctrine:projections')->assertFailed();
    }

    /**
     * "Nothing was generated" has two causes and used to have one
     * message, which sent people to check a mapping that was fine.
     */
    #[Test]
    public function a_pattern_matching_nothing_says_so(): void
    {
        config()->set('doctrine-projections.entities.only', ['Nothing\\Matches\\*']);

        $this->command('doctrine:projections')
            ->expectsOutputToContain('entities.only')
            ->assertFailed();
    }

    #[Test]
    public function an_empty_mapping_says_something_else(): void
    {
        $this->app?->singleton(
            EntityManagerInterface::class,
            static fn (): EntityManagerInterface => EntityManagerFactory::forFixtures('Empty'),
        );

        $this->command('doctrine:projections')
            ->expectsOutputToContain('Is the EntityManager mapping anything?')
            ->assertFailed();
    }

    /**
     * A checkout with `core.autocrlf=true` turns the generated `\n` into
     * `\r\n`, and a byte comparison then reported every projection as out
     * of date forever — regenerating did not help, because the next
     * checkout put them back.
     */
    #[Test]
    public function check_tolerates_windows_line_endings_in_the_committed_files(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        foreach (File::files($this->output) as $file) {
            File::put(
                $file->getPathname(),
                str_replace("\n", "\r\n", File::get($file->getPathname())),
            );
        }

        $this->command('doctrine:projections --check')->assertSuccessful();
    }

    /** And a real difference is still a real difference. */
    #[Test]
    public function check_still_fails_on_an_actual_change(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        File::put(
            $this->output.'/Account.php',
            str_replace('accounts', 'something_else', File::get($this->output.'/Account.php')),
        );

        $this->command('doctrine:projections --check')->assertFailed();
    }

    /** A relative `path` is resolved against the working directory. */
    #[Test]
    public function a_relative_path_works(): void
    {
        $previous = getcwd();
        self::assertIsString($previous);

        File::ensureDirectoryExists($this->output);
        chdir($this->output);

        config()->set('doctrine-projections.path', 'models/projections');

        try {
            $this->command('doctrine:projections')->assertSuccessful();

            self::assertFileExists($this->output.'/models/projections/Account.php');
        } finally {
            chdir($previous);
        }
    }

    /** `path` naming a file rather than a directory has to fail, not half-run. */
    #[Test]
    public function a_path_that_is_a_file_fails(): void
    {
        File::ensureDirectoryExists($this->output);

        $file = $this->output.'/not-a-directory';
        File::put($file, 'x');

        config()->set('doctrine-projections.path', $file);

        $this->command('doctrine:projections')->assertFailed();

        self::assertSame('x', File::get($file), 'and does not overwrite it');
    }

    /**
     * The package never builds an EntityManager; it uses whatever the
     * application bound. With nothing bound, the failure should name the
     * thing that is missing.
     */
    #[Test]
    public function no_bound_entity_manager_says_which_binding_is_missing(): void
    {
        $this->app?->forgetInstance(EntityManagerInterface::class);
        $this->app?->offsetUnset(EntityManagerInterface::class);

        try {
            $this->command('doctrine:projections')->run();
            self::fail('expected the missing binding to surface');
        } catch (\Throwable $e) {
            self::assertStringContainsString('EntityManagerInterface', $e->getMessage());
        }
    }

    /**
     * A relation whose target was excluded is skipped with a warning, and
     * `--check` has to agree with that — the same filter runs in both, so
     * the excluded entity is neither generated nor reported as orphaned.
     */
    #[Test]
    public function an_excluded_entity_is_consistent_between_generate_and_check(): void
    {
        config()->set('doctrine-projections.entities.except', ['*\\Profile']);

        $this->command('doctrine:projections')
            ->expectsOutputToContain('Skipped relation')
            ->assertSuccessful();

        self::assertFileDoesNotExist($this->output.'/Profile.php');
        self::assertFileExists($this->output.'/Account.php');

        $this->command('doctrine:projections --check')->assertSuccessful();
    }

    /** Un-excluding it makes `--check` fail until it is regenerated. */
    #[Test]
    public function un_excluding_an_entity_makes_check_fail(): void
    {
        config()->set('doctrine-projections.entities.except', ['*\\Profile']);
        $this->command('doctrine:projections')->assertSuccessful();

        config()->set('doctrine-projections.entities.except', []);
        $this->command('doctrine:projections --check')->assertFailed();
    }

    /**
     * A single file in the output directory replaced by a symlink. The
     * guard reads through it, so a symlink to a hand-written model is
     * still a hand-written model.
     */
    #[Test]
    public function a_symlinked_file_in_the_output_directory_is_read_through(): void
    {
        File::ensureDirectoryExists($this->output);

        $real = $this->output.'-handwritten.php';
        File::put($real, "<?php\n\nclass Invoice {}\n");

        symlink($real, $this->output.'/Invoice.php');

        try {
            $this->command('doctrine:projections')->assertFailed();

            self::assertSame("<?php\n\nclass Invoice {}\n", File::get($real));
        } finally {
            @unlink($this->output.'/Invoice.php');
            @unlink($real);
        }
    }
}
