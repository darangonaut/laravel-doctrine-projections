<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Console;

use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Generation\EntityFilter;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Generation\RenderedProjection;
use Darangonaut\DoctrineProjections\Support\Config;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Thin wrapper: the generator does the work, this handles the console and
 * the filesystem.
 *
 * Everything is rendered into memory first and the directory is touched
 * only once every model succeeded — a failure halfway through must not
 * leave the application without models.
 */
final class GenerateProjectionsCommand extends Command
{
    protected $signature = 'doctrine:projections
                            {--dry : Render and report, write nothing}
                            {--check : Fail if regenerating would change anything (for CI)}';

    protected $description = 'Generate read-only Eloquent projections from Doctrine mapping';

    /** The header every generated projection carries — see ProjectionGenerator::render(). */
    private const GENERATED_MARKER = 'GENERATED — do not edit.';

    public function handle(EntityManagerInterface $em): int
    {
        $namespace = Config::string('doctrine-projections.namespace');
        $path = Config::string('doctrine-projections.path');

        try {
            $projections = (new ProjectionGenerator($em, $namespace, self::filter()))->generate();
        } catch (DuplicateProjectionName $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($projections === []) {
            $this->components->error('No entities found. Is the EntityManager mapping anything?');

            return self::FAILURE;
        }

        foreach ($projections as $projection) {
            foreach ($projection->warnings as $warning) {
                $this->components->warn($warning);
            }
        }

        if ($this->option('check')) {
            return $this->check($projections, $path);
        }

        if (! $this->option('dry')) {
            File::ensureDirectoryExists($path);

            $foreign = self::handWrittenFilesIn($path);

            if ($foreign !== []) {
                $this->components->error(
                    'The output directory holds PHP files this command did not generate, so it '
                    .'will not touch it:',
                );

                foreach ($foreign as $file) {
                    $this->line('  '.$file);
                }

                $this->newLine();
                $this->line('  `path` in config/doctrine-projections.php should point at a directory');
                $this->line('  of its own — the contents are build output and get replaced.');

                return self::FAILURE;
            }

            foreach (self::phpFilesIn($path) as $stale) {
                File::delete($stale);
            }
        }

        foreach ($projections as $projection) {
            if (! $this->option('dry')) {
                File::put($path.'/'.$projection->className.'.php', $projection->code);
            }

            $this->components->twoColumnDetail(
                $namespace.'\\'.$projection->className,
                $projection->tableName,
            );
        }

        $this->components->info(sprintf(
            '%d projection(s) generated%s.',
            count($projections),
            $this->option('dry') ? ' (dry run, nothing written)' : '',
        ));

        return self::SUCCESS;
    }

    private static function filter(): EntityFilter
    {
        return new EntityFilter(
            self::patterns('doctrine-projections.entities.only'),
            self::patterns('doctrine-projections.entities.except'),
        );
    }

    /** @return list<string> */
    private static function patterns(string $key): array
    {
        $value = config($key, []);

        return is_array($value)
            ? array_values(array_filter($value, is_string(...)))
            : [];
    }

    /** @return list<string> */
    private static function phpFilesIn(string $dir): array
    {
        return array_values(array_filter(File::glob($dir.'/*.php'), is_string(...)));
    }

    /**
     * PHP files in the output directory that this command did not write.
     *
     * The directory is wiped on every run, and `path` is one config value
     * away from being somewhere that matters — `app_path('Models')`
     * instead of `app_path('Models/Projections')` is an easy thing to
     * type, and every hand-written model would go with it. Generated
     * files carry a marker in their header; anything without one means
     * the path is pointing at the wrong place.
     *
     * @return list<string>
     */
    private static function handWrittenFilesIn(string $dir): array
    {
        $foreign = [];

        foreach (self::phpFilesIn($dir) as $file) {
            if (! str_contains(File::get($file), self::GENERATED_MARKER)) {
                $foreign[] = $file;
            }
        }

        return $foreign;
    }

    /**
     * CI mode: assert the committed files already match the mapping.
     *
     * The failure this catches is a deploy where someone changed an entity
     * and forgot to regenerate — the projection then silently lacks the new
     * column.
     *
     * @param  array<string, RenderedProjection>  $projections
     */
    private function check(array $projections, string $path): int
    {
        $stale = [];

        foreach ($projections as $projection) {
            $file = $path.'/'.$projection->className.'.php';

            if (! File::exists($file)) {
                $stale[] = $projection->className.' — missing';

                continue;
            }

            if (File::get($file) !== $projection->code) {
                $stale[] = $projection->className.' — out of date';
            }
        }

        foreach (self::phpFilesIn($path) as $existing) {
            $class = pathinfo($existing, PATHINFO_FILENAME);

            if (! isset($projections[$class])) {
                $stale[] = $class.' — orphaned, no entity maps to it';
            }
        }

        if ($stale !== []) {
            $this->components->error('Projections do not match the mapping:');
            foreach ($stale as $line) {
                $this->line('  '.$line);
            }
            $this->newLine();
            $this->line('  Run `php artisan doctrine:projections` and commit the result.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('%d projection(s) up to date.', count($projections)));

        return self::SUCCESS;
    }
}
