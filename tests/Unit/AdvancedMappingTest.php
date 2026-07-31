<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Generation\RenderedProjection;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine features that reshape the columns without changing the entity
 * that declares them. The generator reads metadata rather than the class,
 * so these should already work — "should" being the reason to check.
 */
final class AdvancedMappingTest extends TestCase
{
    /** @return array<string, RenderedProjection> */
    private function generate(string $fixture): array
    {
        return (new ProjectionGenerator(
            EntityManagerFactory::forFixtures($fixture),
            'Advanced'.$fixture.'Projections',
        ))->generate();
    }

    /**
     * An embeddable inside an embeddable: the prefixes compose, so a
     * column is `from_coord_lat` rather than either half alone.
     */
    #[Test]
    public function nested_embeddables_compose_their_prefixes(): void
    {
        $rendered = $this->generate('Advanced');

        self::assertSame(['Delivery'], array_keys($rendered), 'neither embeddable gets a projection');

        $code = $rendered['Delivery']->code;

        self::assertStringContainsString('@property string $from_label', $code);
        self::assertStringContainsString('@property string $from_coord_lat', $code);
        self::assertStringContainsString('@property string $from_coord_lng', $code);
    }

    /**
     * `#[ORM\Entity(readOnly: true)]` is Doctrine's own idea of read-only
     * — it tells the UnitOfWork not to track changes. It says nothing
     * about reading, so the projection is an ordinary one.
     */
    #[Test]
    public function a_doctrine_read_only_entity_is_projected_normally(): void
    {
        $code = $this->generate('Advanced')['Delivery']->code;

        self::assertStringContainsString("protected \$table = 'deliveries';", $code);
        self::assertStringContainsString('use ReadOnlyModel;', $code);
    }

    /**
     * An attribute override renames an inherited column on one child
     * without touching the superclass. Reading the class would miss it;
     * reading metadata does not.
     */
    #[Test]
    public function an_attribute_override_renames_the_inherited_column(): void
    {
        $code = $this->generate('Overrides')['Article']->code;

        self::assertStringContainsString('@property string $headline', $code);
        self::assertStringNotContainsString('$title', $code, 'the superclass name must not survive');
    }
}
