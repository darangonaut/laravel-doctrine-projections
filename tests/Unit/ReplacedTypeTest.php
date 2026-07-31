<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A replacement for a built-in type — the shape `Type::overrideType()` takes. */
final class FrozenDateTimeType extends DateTimeImmutableType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable('2000-01-01 00:00:00');
    }
}

/**
 * `Type::overrideType()` swaps the class behind a built-in type name and
 * leaves the name alone. The custom-type warning keyed off the name, so
 * it stayed silent: the projection got the cast for the original type
 * while the entity got whatever the replacement decided, and the two
 * quietly meant different things by the same column.
 *
 * The registry is process-global, so the override is put back afterwards
 * — a leaked one would change how every later test reads a date.
 */
final class ReplacedTypeTest extends TestCase
{
    private const NAME = 'datetime_immutable';

    private Type $original;

    protected function setUp(): void
    {
        $this->original = Type::getType(self::NAME);
    }

    protected function tearDown(): void
    {
        Type::overrideType(self::NAME, $this->original);
    }

    /** @return list<string> */
    private function warnings(): array
    {
        $projections = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Superclass'),
            'ReplacedTypeProjections',
        ))->generate();

        return $projections['Author']->warnings;
    }

    #[Test]
    public function the_stock_type_says_nothing(): void
    {
        self::assertSame([], $this->warnings());
    }

    #[Test]
    public function a_replaced_builtin_type_is_reported(): void
    {
        Type::overrideType(self::NAME, FrozenDateTimeType::class);

        $warnings = $this->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('datetime_immutable', $warnings[0]);
        self::assertStringContainsString('FrozenDateTimeType', $warnings[0]);
        self::assertStringContainsString('created_at', $warnings[0]);
    }
}
