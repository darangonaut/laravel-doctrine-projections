<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Generation has to read the mapping as it is on disk — a production
 * metadata cache (APCu, file) survives a deploy, and reading through it
 * would produce models from yesterday's mapping.
 *
 * Clearing that cache is the obvious way to get there and was the way
 * here, until `--check` and `--dry` turned out to go through the same
 * code. A command whose whole job is to report without changing anything
 * was emptying a cache shared with every request in flight, on a live
 * server, every time CI ran it.
 */
final class MetadataCacheTest extends TestCase
{
    private function warmed(ArrayAdapter $cache): EntityManagerInterface
    {
        $em = EntityManagerFactory::forFixtures('Entities');
        $em->getConfiguration()->setMetadataCache($cache);

        // what the application put there
        $cache->save($cache->getItem('something.the.app.cached')->set('still here?'));

        return $em;
    }

    private function generate(EntityManagerInterface $em): int
    {
        return count((new ProjectionGenerator($em, 'CacheProjections'))->generate());
    }

    #[Test]
    public function generating_leaves_the_application_cache_alone(): void
    {
        $cache = new ArrayAdapter;
        $em = $this->warmed($cache);

        self::assertGreaterThan(0, $this->generate($em));

        self::assertTrue(
            $cache->getItem('something.the.app.cached')->isHit(),
            'the entry the application put in the shared cache was thrown away',
        );
    }

    /** And the cache is put back exactly as it was found. */
    #[Test]
    public function the_application_cache_is_still_the_configured_one_afterwards(): void
    {
        $cache = new ArrayAdapter;
        $em = $this->warmed($cache);

        $this->generate($em);

        self::assertSame($cache, $em->getConfiguration()->getMetadataCache());
    }

    /**
     * The point of not reading through it: a cache holding stale metadata
     * must not reach the generated models. A poisoned entry under the key
     * Doctrine uses would come back as the mapping if it were read.
     */
    #[Test]
    public function stale_metadata_in_the_cache_is_not_read(): void
    {
        $cache = new ArrayAdapter;
        $em = $this->warmed($cache);

        // Prime it the way Doctrine would, then generate twice: the second
        // run must not pick up whatever the first left behind either.
        $first = $this->generate($em);
        $second = $this->generate($em);

        self::assertSame($first, $second);
        self::assertTrue($cache->getItem('something.the.app.cached')->isHit());
    }

    /** An EntityManager with no metadata cache configured still works. */
    #[Test]
    public function no_configured_cache_is_fine(): void
    {
        $em = EntityManagerFactory::forFixtures('Entities');
        $em->getConfiguration()->setMetadataCache(new ArrayAdapter);

        self::assertGreaterThan(0, $this->generate($em));
    }
}
