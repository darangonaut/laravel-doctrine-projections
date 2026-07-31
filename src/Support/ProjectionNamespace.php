<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use Darangonaut\DoctrineProjections\Exceptions\InvalidNamespace;

/**
 * The configured namespace, in the one form a `namespace` statement will
 * take.
 *
 * `'\App\Models\Projections'` is a natural thing to write in config — it
 * is how you write the same name almost everywhere else in PHP — and
 * `'App\Models\Projections\'` is an easy trailing-slash habit. Both went
 * straight into the generated files as `namespace \App\Models\Projections;`
 * and `namespace App\Models\Projections\;`, which are parse errors.
 *
 * The command reported success and wrote every file. Nothing failed until
 * the application autoloaded one, at which point the error pointed at
 * generated code rather than at the config line that caused it.
 */
final class ProjectionNamespace
{
    private const SEGMENT = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';

    public static function normalise(string $namespace): string
    {
        $trimmed = trim($namespace, '\\');

        if ($trimmed === '') {
            throw InvalidNamespace::because($namespace, 'it is empty');
        }

        foreach (explode('\\', $trimmed) as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw InvalidNamespace::because(
                    $namespace,
                    sprintf('"%s" is not a legal namespace segment', $segment),
                );
            }
        }

        return $trimmed;
    }
}
