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

    /**
     * A path holding glob syntax — `~/work [old]/app/Models/Projections`
     * is an ordinary enough thing to check a repository out into.
     *
     * `glob()` reads `[old]` as a character class over the whole path and
     * matches nothing, so every listing of the directory came back empty:
     * the guard above stopped protecting hand-written models, stale files
     * stopped being deleted, and `--check` stopped reporting orphans. All
     * three silently, over a directory name nobody involved chose.
     */
    #[Test]
    public function a_path_containing_glob_syntax_is_still_read(): void
    {
        $bracketed = $this->output.'/work [old]/Projections';

        File::ensureDirectoryExists($bracketed);
        File::put($bracketed.'/Invoice.php', "<?php\n\nclass Invoice {}\n");

        config()->set('doctrine-projections.path', $bracketed);

        $this->command('doctrine:projections')->assertFailed();

        self::assertSame(
            "<?php\n\nclass Invoice {}\n",
            File::get($bracketed.'/Invoice.php'),
            'the hand-written file must survive a directory whose name looks like a pattern',
        );

        self::assertFileDoesNotExist($bracketed.'/Account.php');
    }

    /** The other half: stale cleanup has to keep working there too. */
    #[Test]
    public function stale_files_are_removed_from_a_path_containing_glob_syntax(): void
    {
        $bracketed = $this->output.'/work [old]/Projections';

        config()->set('doctrine-projections.path', $bracketed);

        $this->command('doctrine:projections')->assertSuccessful();

        File::put(
            $bracketed.'/Gone.php',
            "<?php\n\n/**\n * GENERATED — do not edit.\n */\nclass Gone {}\n",
        );

        $this->command('doctrine:projections --check')->assertFailed();
        $this->command('doctrine:projections')->assertSuccessful();

        self::assertFileDoesNotExist($bracketed.'/Gone.php');
        self::assertFileExists($bracketed.'/Account.php');
    }

    /**
     * A regenerate can land while the application is serving.
     *
     * The directory used to be emptied before anything was written, so
     * there was a window in which every projection was missing — any
     * request that had not yet autoloaded one got "Class not found" until
     * the run finished. Files are now written first, each renamed over its
     * target in one step, and only genuinely orphaned ones are deleted.
     *
     * The observable half of that: a run which fails partway must leave
     * the previous projections where they were, rather than having already
     * deleted them.
     */
    #[Test]
    public function a_failed_run_leaves_the_previous_projections_in_place(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        $before = File::get($this->output.'/Account.php');

        // stale too, so the cleanup path is in play as well
        File::put(
            $this->output.'/Gone.php',
            "<?php\n\n/**\n * GENERATED — do not edit.\n */\nclass Gone {}\n",
        );

        // A directory where the temp file wants to go: File::put cannot
        // write it, so the run fails on the first projection.
        File::ensureDirectoryExists(sprintf('%s/Account.php.%d.tmp', $this->output, getmypid()));

        try {
            $this->command('doctrine:projections')->assertFailed();

            self::assertSame($before, File::get($this->output.'/Account.php'), 'the old model must survive');
            self::assertFileExists($this->output.'/Gone.php', 'nothing was deleted before the failure');
        } finally {
            File::deleteDirectory(sprintf('%s/Account.php.%d.tmp', $this->output, getmypid()));
        }
    }

    /** A successful run leaves no temp files behind. */
    #[Test]
    public function no_temp_files_are_left_behind(): void
    {
        $this->command('doctrine:projections')->assertSuccessful();

        $leftovers = array_filter(
            scandir($this->output) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.tmp'),
        );

        self::assertSame([], array_values($leftovers));
    }
}
