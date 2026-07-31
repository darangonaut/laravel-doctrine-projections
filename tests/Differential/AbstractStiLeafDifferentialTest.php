<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass\Car;
use Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass\Minivan;
use Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass\Truck;
use Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass\Van;
use Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass\Vehicle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine lets an abstract class sit outside the DiscriminatorMap — the
 * exception it throws for a concrete one says as much. Two shapes come out
 * of that: an abstract class with concrete subclasses, which owns their
 * rows, and one with none, which can own no row at all.
 *
 * The second is the interesting one. No row can ever carry a discriminator
 * that is not in the map, so the answer is always empty — which is exactly
 * what Doctrine emits, as `WHERE 1=0`.
 */
final class AbstractStiLeafDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('UnmappedSubclass', 'DifferentialAbstractSti'.getmypid());

        $car = new Car;
        $car->label = 'auto';
        $car->doors = 5;

        $minivan = new Minivan;
        $minivan->label = 'dodavka';

        $this->harness->em()->persist($car);
        $this->harness->em()->persist($minivan);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /**
     * The Doctrine side goes through DQL rather than `findAll()`.
     *
     * Not a preference: the persister behind `findAll()` builds
     * `kind IN (…)` from the class's discriminator values, and for a class
     * that has none it emits `IN ()` — which SQLite tolerates and MySQL
     * rejects as a syntax error. That is Doctrine's own edge, not the
     * projection's, and DQL is the side of Doctrine that handles it (it
     * emits `WHERE 1=0`).
     *
     * @param  class-string<object>  $entityClass
     * @return array<string, int>
     */
    private function counts(string $entityClass, string $projection): array
    {
        $doctrine = $this->harness->em()
            ->createQuery('SELECT COUNT(e) FROM '.$entityClass.' e')
            ->getSingleScalarResult();

        return [
            'doctrine' => (int) $doctrine,
            'projection' => $this->harness->projection($projection)::query()->count(),
        ];
    }

    #[Test]
    public function an_abstract_class_with_nothing_below_it_owns_no_rows(): void
    {
        $counts = $this->counts(Truck::class, 'Truck');

        self::assertSame(0, $counts['doctrine'], 'Doctrine emits WHERE 1=0 for this class');
        self::assertSame($counts['doctrine'], $counts['projection']);
    }

    #[Test]
    public function an_abstract_class_owns_the_rows_of_its_concrete_subclasses(): void
    {
        $counts = $this->counts(Van::class, 'Van');

        self::assertSame(1, $counts['doctrine']);
        self::assertSame($counts['doctrine'], $counts['projection']);
    }

    #[Test]
    public function the_concrete_classes_and_the_root_still_agree(): void
    {
        self::assertSame(['doctrine' => 2, 'projection' => 2], $this->counts(Vehicle::class, 'Vehicle'));
        self::assertSame(['doctrine' => 1, 'projection' => 1], $this->counts(Car::class, 'Car'));
        self::assertSame(['doctrine' => 1, 'projection' => 1], $this->counts(Minivan::class, 'Minivan'));
    }
}
