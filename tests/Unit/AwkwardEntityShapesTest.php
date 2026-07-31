<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Generation\RenderedProjection;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Entities shaped in ways nothing in the package reads for, but which
 * exist in real codebases.
 */
final class AwkwardEntityShapesTest extends TestCase
{
    /** @return array<string, RenderedProjection> */
    private function generate(string $fixture): array
    {
        return (new ProjectionGenerator(
            EntityManagerFactory::forFixtures($fixture),
            'Awkward'.$fixture.'Projections',
        ))->generate();
    }

    /**
     * An entity with no namespace. `class_basename()` on a global class
     * returns the class itself, which is the right answer here — but the
     * collision guards compare basenames, so it is worth knowing they do
     * not trip over one.
     */
    #[Test]
    public function an_entity_in_the_global_namespace_is_projected(): void
    {
        $rendered = $this->generate('GlobalNamespace');

        self::assertSame(['GlobalThing'], array_keys($rendered));

        $code = $rendered['GlobalThing']->code;

        self::assertStringContainsString("protected \$table = 'global_things';", $code);
        self::assertStringContainsString('Source: GlobalThing', $code);
    }

    /**
     * A boolean key gets Eloquent's numeric key type. The reason is in
     * BooleanKeyDifferentialTest: with 'string', `find(false)` looked for
     * the empty string and found nothing.
     */
    #[Test]
    public function a_boolean_key_is_not_declared_as_a_string_key(): void
    {
        $code = $this->generate('BooleanKey')['Flag']->code;

        self::assertStringContainsString("protected \$primaryKey = 'enabled';", $code);
        self::assertStringNotContainsString("protected \$keyType = 'string';", $code);
        self::assertStringContainsString('public $incrementing = false;', $code);
    }

    /**
     * A pure enum behind `enumType:` is a mapping neither side can
     * round-trip: Doctrine reads `->value`, which a pure enum has not
     * got, and Laravel casts by case name. Measured — the Doctrine insert
     * dies on a NOT NULL violation after a PHP warning.
     */
    #[Test]
    public function a_pure_enum_column_is_reported(): void
    {
        $projection = $this->generate('PureEnum')['Swatch'];

        self::assertCount(1, $projection->warnings);
        self::assertStringContainsString('pure enum Colour', $projection->warnings[0]);
        self::assertStringContainsString('backing type', $projection->warnings[0]);
    }

    /**
     * A blob's shape through the projection depends on the driver, so the
     * warning names both rather than promising one.
     */
    #[Test]
    public function a_blob_column_is_reported(): void
    {
        $warnings = $this->generate('Shapes3')['Listing']->warnings;

        self::assertCount(1, $warnings);
        self::assertStringContainsString('thumbnail', $warnings[0]);
        self::assertStringContainsString('whatever the driver returns', $warnings[0]);
    }
}
