<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered\Album;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered\Track;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Carrying `#[ORM\OrderBy]` onto the relation put an `ORDER BY` inside
 * something Laravel also builds subqueries from. Aggregates and existence
 * checks are the things that could have quietly broken — or, on a
 * stricter driver, loudly.
 *
 * They do not: Laravel drops the ordering when it rewrites the relation
 * into a subquery. This test is here so that stays true.
 */
final class OrderedAggregatesTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Ordered', 'DifferentialAggregates'.getmypid());

        $album = new Album;
        $album->title = 'Dvojalbum';

        foreach ([[1, 1], [2, 2], [1, 2], [2, 1]] as $index => [$disc, $position]) {
            $track = new Track;
            $track->title = 'stopa '.$index;
            $track->position = $position;
            $track->discNumber = $disc;
            $track->album = $album;

            $album->tracks->add($track);

            $this->harness->em()->persist($track);
        }

        $empty = new Album;
        $empty->title = 'Prázdny';

        $this->harness->em()->persist($album);
        $this->harness->em()->persist($empty);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @return class-string<Model> */
    private function album(): string
    {
        return $this->harness->projection('Album');
    }

    #[Test]
    public function the_count_subquery_carries_no_ordering(): void
    {
        $sql = $this->album()::query()->withCount('tracks')->toSql();

        self::assertStringContainsString('count(*)', $sql);
        self::assertStringNotContainsString('order by', strtolower($sql));
    }

    #[Test]
    public function aggregates_over_an_ordered_relation_are_correct(): void
    {
        $album = $this->album()::query()->withCount('tracks')->withSum('tracks', 'position')->first();

        self::assertNotNull($album);
        self::assertSame(4, $album->getAttribute('tracks_count'));

        $sum = $album->getAttribute('tracks_sum_position');

        self::assertIsNumeric($sum);
        self::assertSame(6, (int) $sum);
    }

    #[Test]
    public function existence_checks_still_work_both_ways(): void
    {
        self::assertSame(1, $this->album()::query()->has('tracks')->count());
        self::assertSame(1, $this->album()::query()->doesntHave('tracks')->count());
        self::assertSame(
            1,
            $this->album()::query()->whereHas('tracks', static fn ($q) => $q->where('position', 1))->count(),
        );
    }

    #[Test]
    public function eager_loading_still_applies_the_ordering(): void
    {
        $album = $this->album()::query()->with('tracks')->where('title', 'Dvojalbum')->first();

        self::assertNotNull($album);

        $tracks = $album->getAttribute('tracks');

        self::assertIsIterable($tracks);

        $positions = [];
        foreach ($tracks as $track) {
            self::assertInstanceOf(Model::class, $track);

            $disc = $track->getAttribute('disc_number');
            $position = $track->getAttribute('position');

            self::assertIsInt($disc);
            self::assertIsInt($position);

            $positions[] = [$disc, $position];
        }

        self::assertSame([[2, 1], [2, 2], [1, 1], [1, 2]], $positions);
    }
}
