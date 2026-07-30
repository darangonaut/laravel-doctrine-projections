<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use BackedEnum;
use DateTimeInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * The invariant behind every differential test: for the same database,
 * the projection must answer what the entity answers.
 *
 * Written against the mapping rather than against a fixture, so adding an
 * entity to a fixture directory extends the coverage without touching a
 * test. That is the point — the bugs found by hand today all lived in
 * mapping shapes nobody had thought to write a test for.
 */
final class Compare
{
    public function __construct(private readonly Harness $harness) {}

    /**
     * Every mapped column, on every row, on both sides.
     *
     * @param  class-string<object>  $entityClass
     */
    public function columns(string $entityClass): void
    {
        $meta = $this->harness->em()->getClassMetadata($entityClass);
        $projection = $this->harness->projection(class_basename($entityClass));

        $entities = $this->harness->em()->getRepository($entityClass)->findAll();

        Assert::assertNotSame([], $entities, "no rows to compare for {$entityClass}");

        foreach ($entities as $entity) {
            $model = $this->matching($meta, $entity, $projection);

            foreach ($meta->getFieldNames() as $field) {
                $column = $meta->getColumnName($field);

                Assert::assertSame(
                    $this->columnValueOf($meta, $entity, $field),
                    $this->normalise($model->getAttribute($column)),
                    sprintf('%s::$%s (column %s) differs between the entity and its projection', $entityClass, $field, $column),
                );
            }
        }
    }

    /**
     * Every to-many association, in order.
     *
     * Order is the whole point: `#[ORM\OrderBy]` was dropped for a long
     * time and the two sides returned the same rows in opposite sequence
     * without anything failing.
     *
     * @param  class-string<object>  $entityClass
     */
    public function associations(string $entityClass): void
    {
        $meta = $this->harness->em()->getClassMetadata($entityClass);
        $projection = $this->harness->projection(class_basename($entityClass));

        $compared = 0;

        foreach ($this->harness->em()->getRepository($entityClass)->findAll() as $entity) {
            $model = $this->matching($meta, $entity, $projection);

            foreach ($meta->getAssociationMappings() as $name => $assoc) {
                $method = Str::camel($name);

                // relations the generator refused (unprojected target, or
                // a join it cannot express) have no method to compare
                if (! method_exists($model, $method)) {
                    continue;
                }

                $targetMeta = $this->harness->em()->getClassMetadata($assoc->targetEntity);

                if (! $assoc instanceof ToManyAssociationMapping) {
                    $this->toOne($entityClass, $name, $meta, $entity, $model, $method, $targetMeta);
                    $compared++;

                    continue;
                }

                $collection = $meta->getFieldValue($entity, $name);
                $related = $model->getAttribute($method);

                Assert::assertIsIterable($collection, "{$entityClass}::\${$name} is not a collection");
                Assert::assertIsIterable($related, "the projection's {$method}() returned no collection");

                $expected = [];
                foreach ($collection as $entitySide) {
                    Assert::assertIsObject($entitySide);
                    $expected[] = $this->identityOf($targetMeta, $entitySide);
                }

                $actual = [];
                foreach ($related as $projectionSide) {
                    Assert::assertInstanceOf(Model::class, $projectionSide);
                    $actual[] = $this->projectedIdentityOf($targetMeta, $projectionSide);
                }

                Assert::assertSame(
                    $expected,
                    $actual,
                    sprintf('%s::$%s returns different rows, or a different order, than its projection', $entityClass, $name),
                );

                // Keys too, unless the mapping indexes the collection —
                // Eloquent relations are always 0..n and the generator
                // warns about that rather than pretending otherwise.
                // Read from the array form: `isIndexed()` carries an
                // assertion its own native return type already satisfies,
                // so calling it reads as always-true to static analysis.
                if (($assoc->toArray()['indexBy'] ?? null) === null) {
                    Assert::assertSame(
                        array_keys(iterator_to_array($collection)),
                        array_keys(iterator_to_array($related)),
                        sprintf('%s::$%s is keyed differently than its projection', $entityClass, $name),
                    );
                }

                $compared++;
            }
        }

        Assert::assertGreaterThan(0, $compared, "no associations were compared for {$entityClass}");
    }

    /**
     * A to-one that points at the wrong row looks exactly like one that
     * points at the right row, so the identity is compared rather than
     * the fact that something came back.
     *
     * @param  ClassMetadata<object>  $meta
     * @param  ClassMetadata<object>  $targetMeta
     */
    private function toOne(
        string $entityClass,
        string $name,
        ClassMetadata $meta,
        object $entity,
        Model $model,
        string $method,
        ClassMetadata $targetMeta,
    ): void {
        $related = $meta->getFieldValue($entity, $name);
        $projected = $model->getAttribute($method);

        $message = sprintf('%s::$%s points somewhere else than its projection does', $entityClass, $name);

        if ($related === null) {
            Assert::assertNull($projected, $message);

            return;
        }

        Assert::assertIsObject($related);
        Assert::assertInstanceOf(Model::class, $projected, $message);

        Assert::assertSame(
            $this->identityOf($targetMeta, $related),
            $this->projectedIdentityOf($targetMeta, $projected),
            $message,
        );
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @param  class-string<Model>  $projection
     */
    private function matching(ClassMetadata $meta, object $entity, string $projection): Model
    {
        $conditions = [];

        foreach ($meta->getIdentifierValues($entity) as $field => $value) {
            $conditions[$meta->getColumnName($field)] = $this->normalise($value);
        }

        $model = $projection::query()->where($conditions)->first();

        Assert::assertNotNull($model, 'the projection has no row matching '.json_encode($conditions));

        return $model;
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @return array<string, mixed>
     */
    private function identityOf(ClassMetadata $meta, object $entity): array
    {
        $identity = [];

        foreach ($meta->getIdentifierValues($entity) as $field => $value) {
            $identity[$meta->getColumnName($field)] = $this->normalise($value);
        }

        return $identity;
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @return array<string, mixed>
     */
    private function projectedIdentityOf(ClassMetadata $meta, Model $model): array
    {
        $identity = [];

        foreach ($meta->getIdentifierFieldNames() as $field) {
            $column = $meta->getColumnName($field);
            $identity[$column] = $this->normalise($model->getAttribute($column));
        }

        return $identity;
    }

    /**
     * What the entity's value looks like in the column.
     *
     * A projection reads the column, so that is the only fair thing to
     * compare it against. Usually the two are the same, but a custom
     * Doctrine type converts on the way out — the entity holds a `Money`
     * where the column holds `EUR 125000` — and Eloquent knows nothing
     * about Doctrine's type registry. Asking the type what it would write
     * keeps that difference from reading as a bug.
     *
     * @param  ClassMetadata<object>  $meta
     */
    private function columnValueOf(ClassMetadata $meta, object $entity, string $field): mixed
    {
        $value = $meta->getFieldValue($entity, $field);

        // Dates and enums are handled below, on both sides at once.
        if (! is_object($value) || $value instanceof DateTimeInterface || $value instanceof BackedEnum) {
            return $this->normalise($value);
        }

        $platform = $this->harness->em()->getConnection()->getDatabasePlatform();

        return $this->normalise(
            Type::getType($meta->getTypeOfField($field) ?? Types::STRING)
                ->convertToDatabaseValue($value, $platform),
        );
    }

    /**
     * Both sides describe the same value in different shapes — Doctrine
     * hands back DateTimeImmutable where Eloquent hands back Carbon — so
     * comparison happens on a form neither of them owns.
     */
    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }
}
