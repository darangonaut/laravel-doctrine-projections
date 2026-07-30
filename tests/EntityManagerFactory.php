<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Minimal EntityManager over a fixture directory. The package does not
 * build EntityManagers in production — that is the host application's
 * job — but the tests need one.
 */
final class EntityManagerFactory
{
    public static function forFixtures(string $dir): EntityManagerInterface
    {
        $config = new Configuration;
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/Fixtures/'.$dir]));
        $config->setProxyDir(sys_get_temp_dir().'/doctrine-projections-proxies');
        $config->setProxyNamespace('DoctrineProjectionsTestProxies');
        $config->setMetadataCache(new ArrayAdapter);
        $config->setQueryCache(new ArrayAdapter);

        // Native lazy objects need PHP 8.4; nothing here depends on
        // proxies, so on 8.3 they simply stay off.
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $config,
        );

        return new EntityManager($connection, $config);
    }
}
