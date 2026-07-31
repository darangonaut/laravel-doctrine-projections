<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Darangonaut\DoctrineProjections\Support\AutoloaderVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `composer dump-autoload --classmap-authoritative` is an ordinary
 * production deploy step, and after it the autoloader answers only from
 * its classmap. A projection generated afterwards does not exist as far
 * as the application is concerned — and the failure arrives much later,
 * as a bare "Class not found" with nothing pointing back at the generate.
 *
 * Measured before writing this: with `--classmap-authoritative` a class
 * created after the dump is invisible; with plain `--optimize` it is
 * found, because that keeps the PSR-4 fallback. Warning on the second
 * would be noise on every optimised deploy, so the two are told apart
 * rather than lumped together.
 */
final class AutoloaderVisibilityTest extends TestCase
{
    private const GENERATED = ['App\\Models\\Projections\\Account'];

    private function loader(bool $authoritative, bool $inClassMap): ClassLoader
    {
        $loader = new ClassLoader;

        if ($inClassMap) {
            $loader->addClassMap([self::GENERATED[0] => __FILE__]);
        } else {
            // PSR-4 pointing at a directory the file is not in: the shape
            // of an app whose projections were written after the dump.
            $loader->setPsr4('App\\Models\\Projections\\', [__DIR__]);
        }

        $loader->setClassMapAuthoritative($authoritative);

        return $loader;
    }

    #[Test]
    public function an_authoritative_loader_that_cannot_see_the_class_is_reported(): void
    {
        $warning = AutoloaderVisibility::warningFor(self::GENERATED, [$this->loader(true, false)]);

        self::assertNotNull($warning);
        self::assertStringContainsString('classmap-authoritative', $warning);
        self::assertStringContainsString('App\\Models\\Projections\\Account', $warning);
        self::assertStringContainsString('composer dump-autoload', $warning);
    }

    #[Test]
    public function an_authoritative_loader_that_already_has_the_class_says_nothing(): void
    {
        self::assertNull(
            AutoloaderVisibility::warningFor(self::GENERATED, [$this->loader(true, true)]),
        );
    }

    /**
     * The `--optimize` case. The classmap is built the same way but the
     * PSR-4 fallback stays, so a file written afterwards loads fine.
     */
    #[Test]
    public function a_non_authoritative_loader_says_nothing(): void
    {
        self::assertNull(
            AutoloaderVisibility::warningFor(self::GENERATED, [$this->loader(false, false)]),
        );
    }

    /**
     * Several loaders are registered whenever a package ships its own
     * vendor directory. One of them finding the class is enough.
     */
    #[Test]
    public function another_loader_finding_the_class_is_enough(): void
    {
        self::assertNull(AutoloaderVisibility::warningFor(self::GENERATED, [
            $this->loader(true, false),
            $this->loader(false, true),
        ]));
    }

    #[Test]
    public function nothing_generated_means_nothing_to_warn_about(): void
    {
        self::assertNull(AutoloaderVisibility::warningFor([], [$this->loader(true, false)]));
    }
}
