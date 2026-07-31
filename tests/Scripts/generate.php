<?php

declare(strict_types=1);

/**
 * Generation, in its own process.
 *
 * Used by OpenBasedirTest, which runs it twice — once unrestricted and
 * once with `open_basedir` pinned to the project — to show that nothing
 * outside the project is needed. Not part of the package: `tests/` is
 * export-ignored.
 */

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$projections = (new ProjectionGenerator(
    EntityManagerFactory::forFixtures('Entities'),
    'BasedirProjections',
))->generate();

echo count($projections), "\n";
