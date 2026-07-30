<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Indexed\Config;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Indexed\Setting;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `indexBy` keys a collection by a field, and an Eloquent relation is
 * always 0..n with no hook to change that which would survive
 * regeneration. So this is a divergence the package cannot remove — the
 * point of the test is that it is reported rather than discovered later.
 *
 * The shape it produces is the familiar one: `$config->settings['tz']` is
 * the setting through the entity and null through the projection.
 */
final class IndexedRelationDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Indexed', 'DifferentialIndexed'.getmypid());

        $config = new Config;
        $config->name = 'app';

        foreach ([['timezone', 'Europe/Bratislava'], ['locale', 'sk'], ['debug', 'false']] as [$key, $value]) {
            $setting = new Setting;
            $setting->key = $key;
            $setting->value = $value;
            $setting->config = $config;

            $config->settings->set($key, $setting);

            $this->harness->em()->persist($setting);
        }

        $this->harness->em()->persist($config);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function the_rows_themselves_still_agree(): void
    {
        (new Compare($this->harness))->associations(Config::class);
    }

    #[Test]
    public function the_divergence_is_reported_at_generation(): void
    {
        $rendered = (new ProjectionGenerator(
            $this->harness->em(),
            'IndexedWarnings'.getmypid(),
        ))->generate();

        $warnings = $rendered['Config']->warnings;

        self::assertCount(1, $warnings);
        self::assertStringContainsString('indexed by "key"', $warnings[0]);
        self::assertStringContainsString("keyBy('setting_key')", $warnings[0], 'the advice must name the column, not the field');
    }

    /**
     * Not an assertion about what the package should do — a record of what
     * it does, so the warning above can be trusted to describe reality.
     */
    #[Test]
    public function the_entity_is_keyed_and_the_projection_is_not(): void
    {
        $config = $this->harness->em()->getRepository(Config::class)->findOneBy(['name' => 'app']);

        self::assertNotNull($config);
        self::assertSame(['timezone', 'locale', 'debug'], array_keys($config->settings->toArray()));

        $projection = $this->harness->projection('Config');
        $model = $projection::query()->where('name', 'app')->first();

        self::assertNotNull($model);

        $settings = $model->getAttribute('settings');

        self::assertIsIterable($settings);
        self::assertSame([0, 1, 2], array_keys(iterator_to_array($settings)));
    }
}
