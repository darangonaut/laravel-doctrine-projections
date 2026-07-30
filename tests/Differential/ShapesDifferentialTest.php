<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes\LongName;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes\Marker;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes\Revision;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Three shapes with nothing in common except that nobody had tried them.
 *
 * A self-referencing OneToOne is the interesting one: both sides point at
 * the same table, so a swapped direction still returns a row — the wrong
 * one. The chain here is deliberately one-way, so `supersedes` and
 * `supersededBy` cannot agree by accident.
 */
final class ShapesDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Shapes', 'DifferentialShapes'.getmypid());
        $this->compare = new Compare($this->harness);

        $old = new Revision;
        $old->label = 'v1';

        $new = new Revision;
        $new->label = 'v2';
        $new->supersedes = $old;
        $old->supersededBy = $new;

        $this->harness->em()->persist($old);
        $this->harness->em()->persist($new);
        $this->harness->em()->persist(new Marker);

        $long = new LongName;
        $long->averageRelativeHumidity = 62;
        $this->harness->em()->persist($long);

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function every_column_agrees(): void
    {
        $this->compare->columns(Revision::class);
        $this->compare->columns(Marker::class);
        $this->compare->columns(LongName::class);
    }

    #[Test]
    public function both_sides_of_the_self_referencing_one_to_one_agree(): void
    {
        $this->compare->associations(Revision::class);
    }

    #[Test]
    public function the_two_directions_are_not_the_same_row(): void
    {
        $projection = $this->harness->projection('Revision');

        $v2 = $projection::query()->where('label', 'v2')->first();

        self::assertNotNull($v2);
        self::assertSame('v1', $this->labelOf($v2->getAttribute('supersedes')));
        self::assertNull($v2->getAttribute('supersededBy'), 'nothing supersedes the newest');

        $v1 = $projection::query()->where('label', 'v1')->first();

        self::assertNotNull($v1);
        self::assertNull($v1->getAttribute('supersedes'));
        self::assertSame('v2', $this->labelOf($v1->getAttribute('supersededBy')));
    }

    private function labelOf(mixed $model): string
    {
        self::assertInstanceOf(Model::class, $model);

        $label = $model->getAttribute('label');

        self::assertIsString($label);

        return $label;
    }

    #[Test]
    public function an_entity_with_nothing_but_a_key_still_reads(): void
    {
        $projection = $this->harness->projection('Marker');

        self::assertSame(1, $projection::query()->count());
        self::assertIsInt($projection::query()->first()?->getAttribute('id'));
    }

    #[Test]
    public function long_identifiers_are_passed_through_untouched(): void
    {
        $projection = $this->harness->projection('LongName');

        self::assertSame('measurement_station_daily_aggregate_readings_by_region', (new $projection)->getTable());
        self::assertSame(62, $projection::query()->first()?->getAttribute('average_relative_humidity_percentage_reading'));
    }
}
