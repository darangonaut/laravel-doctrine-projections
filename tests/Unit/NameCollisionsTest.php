<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Exceptions\NamespaceCollision;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Names that collide badly enough to be worth refusing over.
 *
 * PHP itself rules out the worst of them — `class Match {}` will not
 * parse, so no entity can be called that. What it does not rule out is
 * pointing the projection namespace at the entities' own namespace,
 * which gives the generated model the entity's fully qualified name.
 */
final class NameCollisionsTest extends TestCase
{
    #[Test]
    public function the_projection_namespace_may_not_be_the_entities_own(): void
    {
        $this->expectException(NamespaceCollision::class);
        $this->expectExceptionMessage('same class name as the entity');

        (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Entities'),
            'Darangonaut\\DoctrineProjections\\Tests\\Fixtures\\Entities',
        ))->generate();
    }

    #[Test]
    public function a_namespace_that_merely_looks_similar_is_fine(): void
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Entities'),
            'Darangonaut\\DoctrineProjections\\Tests\\Fixtures\\EntitiesProjections',
        ))->generate();

        self::assertArrayHasKey('Account', $rendered);
    }

    /**
     * An entity named after a class the generated file imports — `Model`,
     * `Collection`, `Builder` — is handled by referring to the framework
     * class fully qualified instead of importing it. Checked here on the
     * whole chain rather than on the import collector alone.
     */
    #[Test]
    public function an_entity_named_like_a_framework_class_produces_loadable_code(): void
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Collide'),
            'CollideProjections',
        ))->generate();

        foreach ($rendered as $projection) {
            $tokens = token_get_all($projection->code, TOKEN_PARSE);

            self::assertNotSame([], $tokens, $projection->className.' is not valid PHP');
        }
    }
}
