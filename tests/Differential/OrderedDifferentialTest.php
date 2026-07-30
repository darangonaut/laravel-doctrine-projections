<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered\Album;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered\Track;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first differential test, on the mapping whose bug motivated the
 * approach: `#[ORM\OrderBy]` was dropped, and the two sides returned the
 * same rows in opposite order while every test stayed green.
 *
 * Nothing here asserts a specific order. It asserts that the two sides
 * agree — which is the only thing a projection has to promise, and the
 * one thing hand-written tests kept forgetting to check.
 */
final class OrderedDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Ordered', 'DifferentialOrdered'.getmypid());
        $this->compare = new Compare($this->harness);

        $album = new Album;
        $album->title = 'Dvojalbum';

        // inserted in an order that is neither the expected one nor its
        // reverse, so agreeing by luck is not on the table
        foreach ([[1, 1, 'disk 1, prvá'], [2, 2, 'disk 2, druhá'], [1, 2, 'disk 1, druhá'], [2, 1, 'disk 2, prvá']] as [$disc, $position, $title]) {
            $track = new Track;
            $track->title = $title;
            $track->position = $position;
            $track->discNumber = $disc;
            $track->album = $album;

            $album->tracks->add($track);

            $this->harness->em()->persist($track);
        }

        $this->harness->em()->persist($album);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function every_column_agrees(): void
    {
        $this->compare->columns(Album::class);
        $this->compare->columns(Track::class);
    }

    #[Test]
    public function every_to_many_association_agrees_including_its_order(): void
    {
        $this->compare->associations(Album::class);
    }
}
