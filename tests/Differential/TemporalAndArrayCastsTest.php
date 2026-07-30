<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Carbon\CarbonImmutable;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Casts\Reading;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Three column types Laravel has no cast for, or the wrong one.
 *
 * A TIME went through the datetime cast and came back as *today* at
 * 14:30 while the entity said 1970-01-01 14:30 — the same clock time on
 * two different days. A `datetimetz` had no cast at all and arrived as a
 * string. A `simple_array` arrived as `dom,kúrenie` instead of a list.
 */
final class TemporalAndArrayCastsTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Casts', 'DifferentialTemporal'.getmypid());

        $reading = new Reading;
        $reading->counter = '1';
        $reading->amount = '1';
        $reading->meta = ['unit' => 'kWh'];
        $reading->tags = ['dom', 'kúrenie'];
        $reading->takenOn = new DateTimeImmutable('2026-07-30');
        $reading->takenAt = new DateTimeImmutable('14:30:00');
        $reading->recordedAt = new DateTimeImmutable('2026-07-30 14:30:00');

        $this->harness->em()->persist($reading);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function projected(string $column): mixed
    {
        $projection = $this->harness->projection('Reading');
        $model = $projection::query()->first();

        self::assertNotNull($model);

        return $model->getAttribute($column);
    }

    private function entity(): Reading
    {
        $reading = $this->harness->em()->getRepository(Reading::class)->find(1);

        self::assertInstanceOf(Reading::class, $reading);

        return $reading;
    }

    #[Test]
    public function a_time_column_is_anchored_where_doctrine_anchors_it(): void
    {
        $time = $this->projected('taken_at');

        self::assertInstanceOf(CarbonImmutable::class, $time);
        self::assertSame('1970-01-01 14:30:00', $time->format('Y-m-d H:i:s'));
        self::assertSame(
            $this->entity()->takenAt->format('Y-m-d H:i:s'),
            $time->format('Y-m-d H:i:s'),
            'the entity puts a TIME at the epoch; a projection saying today is a different value',
        );
    }

    #[Test]
    public function a_timezone_aware_column_is_a_date_not_a_string(): void
    {
        $recorded = $this->projected('recorded_at');

        self::assertInstanceOf(DateTimeInterface::class, $recorded);
        self::assertSame(
            $this->entity()->recordedAt->getTimestamp(),
            $recorded->getTimestamp(),
            'same instant on both sides',
        );
    }

    #[Test]
    public function a_simple_array_column_is_a_list(): void
    {
        self::assertSame(['dom', 'kúrenie'], $this->projected('tags'));
        self::assertSame($this->entity()->tags, $this->projected('tags'));
    }

    #[Test]
    public function a_null_simple_array_is_an_empty_list_as_doctrine_reports_it(): void
    {
        $reading = new Reading;
        $reading->counter = '2';
        $reading->amount = '2';
        $reading->meta = [];
        $reading->tags = null;

        $this->harness->em()->persist($reading);
        $this->harness->em()->flush();
        $this->harness->forget();

        $projection = $this->harness->projection('Reading');
        $model = $projection::query()->where('counter', '2')->first();

        self::assertNotNull($model);
        self::assertSame([], $model->getAttribute('tags'));
    }

    #[Test]
    public function a_date_column_does_not_gain_a_time(): void
    {
        $taken = $this->projected('taken_on');

        self::assertInstanceOf(CarbonImmutable::class, $taken);
        self::assertSame('2026-07-30 00:00:00', $taken->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function every_column_still_agrees_with_the_entity(): void
    {
        (new Compare($this->harness))->columns(Reading::class);
    }
}
