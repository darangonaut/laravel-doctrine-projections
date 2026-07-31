<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use Composer\Autoload\ClassLoader;

/**
 * Whether the classes just written on disk can actually be loaded.
 *
 * Normally the answer is trivially yes — a PSR-4 loader looks at the
 * filesystem every time. It stops being trivial after
 * `composer dump-autoload --classmap-authoritative`, which is a common
 * production deploy step: the loader then answers only from the classmap
 * it was given and never looks at a file again. A projection generated
 * after that dump does not exist as far as the application is concerned,
 * and the failure arrives later as a bare "Class not found" with nothing
 * pointing back here.
 *
 * Plain `--optimize` is fine and must not warn: it builds the same
 * classmap but keeps the PSR-4 fallback, so a new file is found anyway.
 * Measured both ways rather than assumed.
 */
final class AutoloaderVisibility
{
    /**
     * @param  list<string>  $classes  fully qualified names just generated
     * @param  iterable<ClassLoader>|null  $loaders  defaults to the ones Composer registered
     */
    public static function warningFor(array $classes, ?iterable $loaders = null): ?string
    {
        if ($classes === [] || ! class_exists(ClassLoader::class)) {
            return null;
        }

        $loaders ??= ClassLoader::getRegisteredLoaders();

        $authoritative = false;

        foreach ($loaders as $loader) {
            $authoritative = $authoritative || $loader->isClassMapAuthoritative();
        }

        if (! $authoritative) {
            return null;
        }

        $invisible = [];

        foreach ($classes as $class) {
            $found = false;

            foreach ($loaders as $loader) {
                $found = $found || $loader->findFile($class) !== false;
            }

            if (! $found) {
                $invisible[] = $class;
            }
        }

        if ($invisible === []) {
            return null;
        }

        return sprintf(
            'The autoloader is classmap-authoritative and %d of the generated class(es) are not in '
            .'its classmap — %s would fail to load. Run `composer dump-autoload` again, or generate '
            .'the projections before dumping it.',
            count($invisible),
            $invisible[0],
        );
    }
}
