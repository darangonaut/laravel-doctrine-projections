<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The same directory listed twice in the mapping paths, once plainly and
 * once through `..`. Easy to end up with when mapping paths are built
 * from config across several packages, and it would put every entity in
 * front of the generator twice.
 *
 * A second copy would trip the duplicate-name guard and fail the whole
 * run — refusing to generate anything because of a repeated config line.
 */
final class DuplicateMappingPathTest extends TestCase
{
    /** @return list<string> */
    private function generate(): array
    {
        $fixtures = __DIR__.'/../Fixtures/Entities';

        $config = new Configuration;
        $config->setMetadataDriverImpl(new AttributeDriver([
            $fixtures,
            $fixtures,
            $fixtures.'/../Entities',
        ]));
        $config->setProxyDir(sys_get_temp_dir().'/doctrine-projections-duplicate-proxies');
        $config->setProxyNamespace('DoctrineProjectionsDuplicateProxies');
        $config->setMetadataCache(new ArrayAdapter);
        $config->setQueryCache(new ArrayAdapter);

        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config),
            $config,
        );

        return array_keys((new ProjectionGenerator($em, 'DuplicatePathProjections'))->generate());
    }

    #[Test]
    public function the_same_path_listed_three_times_still_yields_one_projection_each(): void
    {
        $classes = $this->generate();

        self::assertSame($classes, array_values(array_unique($classes)));
        self::assertContains('Account', $classes);
        self::assertContains('Profile', $classes);
    }
}
