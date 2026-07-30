<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An embeddable has no table, so it gets no projection of its own — but
 * its columns are real columns on whatever embeds it, and Doctrine names
 * those fields `billing.street` while the column is `billing_street`.
 * Reach for the field name and the generated model documents properties
 * that do not exist.
 */
final class EmbeddableTest extends TestCase
{
    private function generate(): string
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Embedded'),
            'Generated\\Projections',
        ))->generate();

        self::assertSame(['Customer'], array_keys($rendered), 'the embeddable must not get a projection of its own');

        return $rendered['Customer']->code;
    }

    #[Test]
    public function embedded_columns_appear_under_their_column_names(): void
    {
        $code = $this->generate();

        self::assertStringContainsString('@property string $billing_street', $code);
        self::assertStringContainsString('@property string $billing_city', $code);
        self::assertStringContainsString('@property string|null $billing_postal_code', $code);
    }

    #[Test]
    public function an_embeddable_without_a_prefix_contributes_bare_column_names(): void
    {
        $code = $this->generate();

        self::assertStringContainsString('@property string $street', $code);
        self::assertStringContainsString('@property string|null $postal_code', $code);
    }

    #[Test]
    public function the_dotted_field_names_never_reach_the_generated_code(): void
    {
        // `billing.street` is what Doctrine calls the field; a property of
        // that name would be nonsense in PHP and unusable from Eloquent
        self::assertStringNotContainsString('billing.', $this->generate());
    }

    #[Test]
    public function the_owner_is_still_an_ordinary_projection(): void
    {
        $code = $this->generate();

        self::assertStringContainsString("protected \$table = 'customers';", $code);
        self::assertStringContainsString('use ReadOnlyModel;', $code);
    }
}
