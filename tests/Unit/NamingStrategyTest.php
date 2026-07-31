<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A naming strategy names the columns the entity never does. The fixture
 * declares no `name:` anywhere, so every column here — including the
 * foreign key, which the entity does not even mention — exists only
 * because the strategy invented it.
 *
 * The failure this rules out is a generator that derives column names
 * from property names on its own: that guess agrees with the default
 * strategy and disagrees with every other one, so it would look correct
 * right up until an application configured one.
 */
final class NamingStrategyTest extends TestCase
{
    /** @return array<string, string> class name => generated code */
    private function generate(): array
    {
        $config = new Configuration;
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures/Naming']));
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir().'/doctrine-projections-naming-proxies');
        $config->setProxyNamespace('DoctrineProjectionsNamingProxies');
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

        foreach ((new ProjectionGenerator($em, 'NamingProjections'))->generate() as $projection) {
            $code[$projection->className] = $projection->code;
        }

        return $code;
    }

    #[Test]
    public function columns_come_from_the_strategy_not_from_the_property_names(): void
    {
        $code = $this->generate()['InvoiceLine'];

        self::assertStringContainsString('@property string $product_name', $code);
        self::assertStringContainsString('@property int $quantity_ordered', $code);
        self::assertStringContainsString("'quantity_ordered' => 'integer',", $code);

        self::assertStringNotContainsString('$productName', $code);
        self::assertStringNotContainsString('quantityOrdered', $code);
    }

    /** The table name is the strategy's too, and it is not a plural. */
    #[Test]
    public function the_table_name_comes_from_the_strategy(): void
    {
        self::assertStringContainsString(
            "protected \$table = 'invoice_line';",
            $this->generate()['InvoiceLine'],
        );
    }

    /**
     * The join column is the sharpest case: nothing in the entity names
     * it, so `tax_rate_id` exists purely because the strategy said so.
     * The relation method keeps the property's own name.
     */
    #[Test]
    public function the_join_column_comes_from_the_strategy_and_the_method_from_the_property(): void
    {
        $code = $this->generate()['InvoiceLine'];

        self::assertStringContainsString('@property int|null $tax_rate_id', $code);
        self::assertStringContainsString('public function taxRate(): BelongsTo', $code);
        self::assertStringContainsString("belongsTo(TaxRate::class, 'tax_rate_id')", $code);
    }

    /**
     * A column called `created_at` is not Laravel's `created_at`: nothing
     * writes it here, and Eloquent must not try.
     */
    #[Test]
    public function a_timestamp_shaped_column_does_not_switch_timestamps_on(): void
    {
        self::assertStringContainsString('public $timestamps = false;', $this->generate()['InvoiceLine']);
    }
}
