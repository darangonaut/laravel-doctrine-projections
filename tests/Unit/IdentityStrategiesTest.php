<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Generation\RenderedProjection;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keys that are not an auto-incrementing integer. Eloquent assumes one
 * unless told otherwise, and the assumption is silent: a string key read
 * as an int would come back mangled rather than missing.
 */
final class IdentityStrategiesTest extends TestCase
{
    /** @return array<string, RenderedProjection> */
    private function generate(): array
    {
        return (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Identity'),
            'IdentityProjections',
        ))->generate();
    }

    #[Test]
    public function an_assigned_key_is_declared_with_its_name_type_and_no_increment(): void
    {
        $code = $this->generate()['Country']->code;

        self::assertStringContainsString("protected \$primaryKey = 'iso';", $code);
        self::assertStringContainsString("protected \$keyType = 'string';", $code);
        self::assertStringContainsString('public $incrementing = false;', $code);
    }

    /**
     * `guid` is a Doctrine type of its own, not a plain string, so the
     * key type has to come from the mapping rather than from the column's
     * PHP shape.
     */
    #[Test]
    public function a_guid_key_is_a_string_key(): void
    {
        $code = $this->generate()['Session']->code;

        self::assertStringContainsString("protected \$keyType = 'string';", $code);
        self::assertStringContainsString('public $incrementing = false;', $code);
    }

    /**
     * Doctrine allows the association itself to be part of the key. That
     * is still a composite key, and the relation still has to work.
     */
    #[Test]
    public function an_association_inside_the_key_is_a_composite_key(): void
    {
        $projection = $this->generate()['Membership'];

        self::assertStringContainsString('protected $primaryKey = null;', $projection->code);
        self::assertStringContainsString(
            "return \$this->belongsTo(Country::class, 'country_iso');",
            $projection->code,
            'the relation is still usable even though the key is not',
        );

        self::assertCount(1, $projection->warnings);
        self::assertStringContainsString('composite key (country, org)', $projection->warnings[0]);
    }
}
