<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Console;

use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
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
    protected $signature = 'doctrine:projections {--dry : Render and report, write nothing}';

    protected $description = 'Generate read-only Eloquent projections from Doctrine mapping';

    public function handle(EntityManagerInterface $em): int
    {
        $namespace = (string) config('doctrine-projections.namespace');
        $path = (string) config('doctrine-projections.path');

        try {
            $projections = (new ProjectionGenerator($em, $namespace))->generate();
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

        if (! $this->option('dry')) {
            File::ensureDirectoryExists($path);

            foreach (File::glob($path.'/*.php') as $stale) {
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
}
