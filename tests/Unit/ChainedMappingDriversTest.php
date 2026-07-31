<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A codebase mid-migration maps half its entities with attributes and the
 * other half with XML, joined by a MappingDriverChain. The generator only
 * ever asks the metadata factory, so the driver behind an entity should
 * be invisible to it — worth checking rather than assuming, because an
 * XML-mapped class carries no attributes at all and anything reading the
 * class instead of the metadata would come back empty-handed.
 */
final class ChainedMappingDriversTest extends TestCase
{
    private const ATTRIBUTES = 'Darangonaut\\DoctrineProjections\\Tests\\Fixtures\\Chain\\Attributes';

    private const XML = 'Darangonaut\\DoctrineProjections\\Tests\\Fixtures\\Chain\\Xml';

    /** @return array<string, string> class name => generated code */
    private function generate(): array
    {
        $chain = new MappingDriverChain;
        $chain->addDriver(new AttributeDriver([__DIR__.'/../Fixtures/Chain/Attributes']), self::ATTRIBUTES);
        $chain->addDriver(new XmlDriver(__DIR__.'/../Fixtures/Chain/Xml', '.dcm.xml'), self::XML);

        $config = new Configuration;
        $config->setMetadataDriverImpl($chain);
        $config->setProxyDir(sys_get_temp_dir().'/doctrine-projections-chain-proxies');
        $config->setProxyNamespace('DoctrineProjectionsChainProxies');
        $config->setMetadataCache(new ArrayAdapter);
        $config->setQueryCache(new ArrayAdapter);

        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config),
            $config,
        );

        $code = [];

        foreach ((new ProjectionGenerator($em, 'ChainProjections'))->generate() as $projection) {
            $code[$projection->className] = $projection->code;
        }

        return $code;
    }

    #[Test]
    public function both_drivers_produce_projections(): void
    {
        $code = $this->generate();

        self::assertSame(['Shipment', 'Carrier'], array_keys($code));
    }

    /**
     * The XML says `column="is_express"` for a property called `express`.
     * Reading the class would have found neither the column name nor the
     * type — the class has three bare public properties and nothing else.
     */
    #[Test]
    public function an_xml_mapped_entity_gets_its_columns_and_casts(): void
    {
        $carrier = $this->generate()['Carrier'];

        self::assertStringContainsString("protected \$table = 'carriers';", $carrier);
        self::assertStringContainsString('@property bool $is_express', $carrier);
        self::assertStringContainsString("'is_express' => 'boolean',", $carrier);
        self::assertStringNotContainsString('$express', $carrier);
    }

    /** An association that crosses from one driver to the other. */
    #[Test]
    public function a_relation_across_the_two_drivers_resolves(): void
    {
        $shipment = $this->generate()['Shipment'];

        self::assertStringContainsString('/** @return BelongsTo<Carrier, $this> */', $shipment);
        self::assertStringContainsString("belongsTo(Carrier::class, 'carrier_id')", $shipment);
    }
}
