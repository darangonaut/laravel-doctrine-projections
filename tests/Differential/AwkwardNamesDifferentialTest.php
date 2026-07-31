<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Awkward\Line;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Awkward\Report;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Identifiers at their limits, and two mapping options that concern
 * writing rather than reading.
 *
 * The table name is 63 characters, PostgreSQL's cutoff. Two columns
 * carry diacritics, which reach the generated file as property names in
 * a docblock and as array keys in `casts()` — both fine in PHP, but the
 * kind of thing worth putting on a row rather than assuming.
 *
 * `orphanRemoval` and `fetch: EAGER` change when and whether Doctrine
 * writes and loads. Neither changes what a row contains, so the
 * projection must be the same either way — and the relation must still
 * return the same rows in the same order.
 */
final class AwkwardNamesDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Awkward', 'DifferentialAwkward'.getmypid());

        $report = new Report;
        $report->month = 'júl';
        $report->second = 7;
        $report->total = 1200;

        $this->harness->em()->persist($report);

        foreach (['první řádek', 'druhý řádek'] as $description) {
            $line = new Line;
            $line->description = $description;
            $line->report = $report;

            $report->lines->add($line);
            $this->harness->em()->persist($line);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function every_column_agrees_with_the_entity(): void
    {
        $compare = new Compare($this->harness);

        $compare->columns(Report::class);
        $compare->columns(Line::class);
    }

    #[Test]
    public function an_eager_orphan_removing_relation_returns_the_same_rows(): void
    {
        (new Compare($this->harness))->associations(Report::class);
    }

    #[Test]
    public function a_column_with_diacritics_is_readable_and_filterable(): void
    {
        $model = $this->harness->projection('Report')::query()->where('mesíc', 'júl')->first();

        self::assertNotNull($model);
        self::assertSame('júl', $model->getAttribute('mesíc'));
        self::assertSame(7, $model->getAttribute('druhá_hodnota'));
    }

    #[Test]
    public function a_sixty_three_character_table_name_survives(): void
    {
        $model = $this->harness->projection('Report')::query()->first();

        self::assertNotNull($model);
        self::assertSame(63, strlen($model->getTable()));
        self::assertSame(1200, $model->getAttribute('total_including_tax_and_shipping_and_handling_and_discounts'));
    }
}
