<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered\Album;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Most modern applications run `Model::shouldBeStrict()`. Generated code
 * has to survive it, and the parts worth doubting are ours: a global
 * scope, a relation carrying an ordering, and a docblock we deliberately
 * leave a column out of.
 */
final class StrictModeTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Ordered', 'DifferentialStrict'.getmypid());

        $album = new Album;
        $album->title = 'Dvojalbum';

        foreach ([[1, 1], [2, 1]] as $index => [$disc, $position]) {
            $track = new Track;
            $track->title = 'stopa '.$index;
            $track->position = $position;
            $track->discNumber = $disc;
            $track->album = $album;

            $album->tracks->add($track);

            $this->harness->em()->persist($track);
        }

        $other = new Album;
        $other->title = 'Druhý album';

        $this->harness->em()->persist($album);
        $this->harness->em()->persist($other);
        $this->harness->em()->flush();
        $this->harness->forget();

        Model::shouldBeStrict();
    }

    protected function tearDown(): void
    {
        Model::shouldBeStrict(false);
        Relation::morphMap([], false);

        parent::tearDown();
    }

    /** @return class-string<Model> */
    private function album(): string
    {
        return $this->harness->projection('Album');
    }

    #[Test]
    public function reading_columns_is_unaffected(): void
    {
        $album = $this->album()::query()->first();

        self::assertNotNull($album);
        self::assertSame('Dvojalbum', $album->getAttribute('title'));
    }

    #[Test]
    public function an_eager_loaded_relation_is_fine(): void
    {
        $album = $this->album()::query()->with('tracks')->first();

        self::assertNotNull($album);

        $tracks = $album->getAttribute('tracks');

        self::assertIsIterable($tracks);
        self::assertCount(2, iterator_to_array($tracks));
    }

    /**
     * Not our behaviour to change — strict mode is asking for exactly
     * this — but worth pinning, because a generated relation is easy to
     * reach for without `with()`.
     */
    #[Test]
    public function a_lazily_loaded_relation_trips_strict_mode_as_it_should(): void
    {
        $albums = $this->album()::query()->get();

        self::assertCount(2, $albums);

        $this->expectExceptionMessageMatches('/lazy load/i');

        $albums->first()?->getAttribute('tracks');
    }

    /**
     * Laravel only arms the lazy-loading guard when it hydrates more than
     * one row (`Builder::hydrate()`), on the reasoning that a single
     * fetched model was asked for deliberately. Worth knowing before
     * concluding that a projection is exempt from strict mode.
     */
    #[Test]
    public function a_single_fetched_model_is_exempt_by_laravels_own_rule(): void
    {
        $album = $this->album()::query()->first();

        self::assertNotNull($album);

        $tracks = $album->getAttribute('tracks');

        self::assertIsIterable($tracks);
    }

    #[Test]
    public function a_column_that_was_not_selected_is_reported_not_guessed(): void
    {
        $album = $this->album()::query()->select('id')->first();

        self::assertNotNull($album);

        $this->expectExceptionMessageMatches('/either does not exist or was not retrieved/i');

        $album->getAttribute('title');
    }

    #[Test]
    public function the_write_lock_still_wins_over_strict_mode(): void
    {
        $album = $this->album()::query()->first();

        self::assertNotNull($album);

        // strict mode also guards discarded attributes; the projection
        // must still refuse for its own reason
        $this->expectExceptionMessageMatches('/read-only projection/i');

        $album->delete();
    }
}
