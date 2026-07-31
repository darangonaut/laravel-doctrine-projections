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
}
