<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Generation\RenderedProjection;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Doctrine\ORM\Mapping\MappingException;
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

    /**
     * Repository class, lifecycle callbacks, entity listeners, second-level
     * cache, indexes and unique constraints all concern the write side or
     * the schema — none of them changes what a row looks like when read.
     *
     * The check is that the two projections differ only in the names: any
     * other difference means one of these features leaked into the output,
     * and a projection that carries write-side machinery is a projection
     * that lies about being read-only.
     */
    #[Test]
    public function write_side_and_schema_features_leave_the_projection_alone(): void
    {
        $rendered = $this->generate('Passive');

        $plain = $rendered['PlainThing']->code;
        $decorated = $rendered['DecoratedThing']->code;

        $normalised = str_replace(
            ['DecoratedThing', 'decorated_things'],
            ['PlainThing', 'plain_things'],
            $decorated,
        );

        self::assertSame($plain, $normalised);

        foreach (['ThingRepository', 'ThingListener', 'READ_ONLY', 'decorated_label_idx', 'prePersist'] as $leak) {
            self::assertStringNotContainsString($leak, $decorated);
        }
    }

    /**
     * An embeddable cannot hold an association — Doctrine refuses the
     * mapping itself, so the generator never sees one. Pinned as a canary:
     * if a later Doctrine allows it, this test fails and the generator
     * needs to decide what such a column becomes.
     */
    #[Test]
    public function doctrine_refuses_an_embeddable_that_holds_an_association(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageMatches('/embeddable/i');

        $this->generate('EmbeddedAssociation');
    }

    /**
     * The association twin of the attribute override: the superclass says
     * the join column is `editor_id`, the entity says it is
     * `assigned_editor_id`. Getting this wrong means a belongsTo() that
     * queries a column the table does not have.
     */
    #[Test]
    public function an_association_override_renames_the_inherited_join_column(): void
    {
        $code = $this->generate('Overrides')['Article']->code;

        self::assertStringContainsString('@property int|null $assigned_editor_id', $code);
        self::assertStringContainsString("belongsTo(Editor::class, 'assigned_editor_id')", $code);
        self::assertStringNotContainsString('editor_id\'', str_replace('assigned_editor_id', 'x', $code));
    }
}
