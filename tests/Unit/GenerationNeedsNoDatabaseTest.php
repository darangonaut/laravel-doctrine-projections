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
 * Generation reads mapping, never the database — which is what makes it
 * safe to run before `migrate`, or in CI with no server at all. Easy to
 * break by reaching for the schema manager for one convenient detail, so
 * it is pinned here: the connection points at a path that cannot exist.
 */
final class GenerationNeedsNoDatabaseTest extends TestCase
{
    #[Test]
    public function projections_generate_against_an_unreachable_connection(): void
    {
        $config = new Configuration;
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures/Entities']));
        $config->setProxyDir(sys_get_temp_dir().'/no-database-proxies');
        $config->setProxyNamespace('NoDatabaseProxies');
        $config->setMetadataCache(new ArrayAdapter);
        $config->setQueryCache(new ArrayAdapter);

        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $em = new EntityManager(
            DriverManager::getConnection(
                ['driver' => 'pdo_sqlite', 'path' => '/nonexistent/directory/database.sqlite'],
                $config,
            ),
            $config,
        );

        $rendered = (new ProjectionGenerator($em, 'OfflineProjections'))->generate();

        self::assertNotSame([], $rendered);
        self::assertArrayHasKey('Account', $rendered);
        self::assertStringContainsString("protected \$table = 'accounts';", $rendered['Account']->code);
    }
}
