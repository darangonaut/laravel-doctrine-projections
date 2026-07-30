<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Projections become files, so two names that a filesystem cannot tell
 * apart are a collision even when PHP can.
 *
 * macOS and Windows are case-insensitive: `Order.php` and `order.php` are
 * one file, and the second written would silently replace the first. The
 * duplicate guard compared names exactly and let that through.
 */
final class FilesystemHazardsTest extends TestCase
{
    #[Test]
    public function two_names_differing_only_in_case_are_a_collision(): void
    {
        $this->expectException(DuplicateProjectionName::class);

        (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('CaseClash'),
            'CaseClashProjections',
        ))->generate();
    }

    #[Test]
    public function names_that_genuinely_differ_are_still_fine(): void
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Entities'),
            'DistinctProjections',
        ))->generate();

        self::assertArrayHasKey('Account', $rendered);
        self::assertArrayHasKey('Profile', $rendered);
    }
}
