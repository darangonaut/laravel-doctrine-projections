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
 * The output directory is wiped on every run, and `path` is one config
 * value away from somewhere that matters: `app_path('Models')` instead of
 * `app_path('Models/Projections')` is an easy thing to type, and every
 * hand-written model in there would be deleted on the next generate.
 *
 * So the command refuses a directory holding PHP it did not write. Stale
 * *generated* files are still cleaned up — that is the whole point of
 * wiping — and only files without the header are treated as somebody's.
 */
final class OutputDirectorySafetyTest extends TestCase
{
    private string $output;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->output = sys_get_temp_dir().'/projections-safety-'.getmypid();

        $app['config']->set('doctrine-projections.namespace', 'Generated\\Safety');
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

    #[Test]
    public function a_hand_written_file_in_the_output_directory_stops_the_run(): void
    {
        File::ensureDirectoryExists($this->output);
        File::put($this->output.'/Invoice.php', "<?php\n\nclass Invoice {}\n");

        $this->command('doctrine:projections')->assertFailed();

        self::assertSame(
            "<?php\n\nclass Invoice {}\n",
            File::get($this->output.'/Invoice.php'),
            'the file must be exactly as it was',
        );

        self::assertFileDoesNotExist($this->output.'/Account.php', 'and nothing else may be written either');
    }

    #[Test]
    public function stale_generated_files_are_still_removed(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        // looks generated, has no entity any more
        File::put(
            $this->output.'/Gone.php',
            "<?php\n\n/**\n * GENERATED — do not edit.\n */\nclass Gone {}\n",
        );

        $this->command('doctrine:projections')->assertSuccessful();

        self::assertFileDoesNotExist($this->output.'/Gone.php');
        self::assertFileExists($this->output.'/Account.php');
    }

    #[Test]
    public function a_non_php_file_is_left_alone(): void
    {
        File::ensureDirectoryExists($this->output);
        File::put($this->output.'/.gitignore', "*\n");

        $this->command('doctrine:projections')->assertSuccessful();

        self::assertSame("*\n", File::get($this->output.'/.gitignore'));
    }

    /**
     * `File::put()` returns false rather than throwing, and the result was
     * not looked at — so a run that wrote nothing still printed
     * "3 projection(s) generated" and exited 0. On a deploy that is a
     * green build over an application left without models.
     */
    #[Test]
    public function a_write_that_fails_is_reported_as_a_failure(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        foreach (array_filter(File::glob($this->output.'/*.php'), is_string(...)) as $file) {
            File::delete($file);
        }

        chmod($this->output, 0555);

        try {
            $this->command('doctrine:projections')->assertFailed();

            self::assertSame([], File::glob($this->output.'/*.php'), 'and nothing was written');
        } finally {
            chmod($this->output, 0755);
        }
    }

    /**
     * A symlinked output directory is ordinary usage — a build step
     * pointing at a shared location — and the guard reads through it.
     */
    #[Test]
    public function a_symlinked_output_directory_works(): void
    {
        $real = $this->output.'-real';
        $link = $this->output.'-link';

        File::ensureDirectoryExists($real);
        @unlink($link);
        symlink($real, $link);

        config()->set('doctrine-projections.path', $link);

        try {
            $this->command('doctrine:projections')->assertSuccessful();
            $this->command('doctrine:projections --check')->assertSuccessful();

            self::assertFileExists($real.'/Account.php', 'the file lands in the real directory');
        } finally {
            @unlink($link);
            File::deleteDirectory($real);
        }
    }

    #[Test]
    public function the_check_run_never_deletes_anything(): void
    {
        File::ensureDirectoryExists($this->output);
        File::put($this->output.'/Invoice.php', "<?php\n\nclass Invoice {}\n");

        $this->command('doctrine:projections --check')->assertFailed();

        self::assertFileExists($this->output.'/Invoice.php');
    }
}
