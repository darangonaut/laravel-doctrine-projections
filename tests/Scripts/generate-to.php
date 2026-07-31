<?php

declare(strict_types=1);

/**
 * Generate into a directory named on the command line, in its own
 * process.
 *
 * Used by ConcurrentGenerationTest to run two generates at once and to
 * read the directory while one is running — neither of which can be
 * arranged inside a single process. Not part of the package: `tests/` is
 * export-ignored.
 *
 * argv: <output directory> [repetitions]
 */

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$output = $argv[1] ?? null;
$rounds = (int) ($argv[2] ?? 1);

if (! is_string($output)) {
    fwrite(STDERR, "usage: generate-to.php <directory> [rounds]\n");
    exit(2);
}

@mkdir($output, 0777, true);

$projections = (new ProjectionGenerator(
    EntityManagerFactory::forFixtures('Entities'),
    'ConcurrentProjections',
))->generate();

for ($round = 0; $round < $rounds; $round++) {
    foreach ($projections as $projection) {
        $file = $output.'/'.$projection->className.'.php';
        $temp = sprintf('%s.%d.tmp', $file, getmypid());

        file_put_contents($temp, $projection->code);
        rename($temp, $file);
    }
}

echo count($projections), "\n";
