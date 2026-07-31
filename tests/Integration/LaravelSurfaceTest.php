<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Darangonaut\DoctrineProjections\Tests\Differential\Harness;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Composite\Seat;
use Darangonaut\DoctrineProjections\Tests\Support\QueuedProjectionJob;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The places the rest of Laravel reaches for a model's key: queues, route
 * binding, and the chunk-by-id family.
 *
 * All of them ask for one value identifying a row, which is exactly what
 * a composite-key projection does not have. Two of them used to answer
 * anyway: `chunkById()` walked three rows and called back with one, and
 * `getRouteKey()` returned null so `route()` produced a link to nowhere.
 * Neither raised anything.
 */
final class LaravelSurfaceTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Composite', 'Surface'.getmypid());

        foreach ([['A', 1], ['A', 2], ['B', 1]] as [$row, $number]) {
            $seat = new Seat;
            $seat->row = $row;
            $seat->number = $number;

            $this->harness->em()->persist($seat);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function seat(): Model
    {
        $seat = $this->harness->projection('Seat')::query()
            ->where('row_letter', 'A')
            ->where('seat_number', 1)
            ->first();

        self::assertNotNull($seat);

        return $seat;
    }

    /**
     * The one that was wrong rather than merely unhelpful: three rows in,
     * one row out, `true` returned.
     */
    #[Test]
    public function chunk_by_id_refuses_rather_than_walking_two_thirds_of_the_table(): void
    {
        self::assertSame(3, $this->harness->projection('Seat')::query()->count());

        $this->expectException(UnsupportedMapping::class);
        $this->expectExceptionMessageMatches('/composite primary key/');

        $this->harness->projection('Seat')::query()->chunkById(1, static fn (): bool => true);
    }

    /** Naming a column that really is unique is a fine way to walk one. */
    #[Test]
    public function chunk_by_id_on_a_named_column_still_works(): void
    {
        $seen = 0;

        $this->harness->projection('Seat')::query()
            ->where('row_letter', 'A')
            ->chunkById(1, static function ($rows) use (&$seen): void {
                $seen += $rows->count();
            }, 'seat_number');

        self::assertSame(2, $seen);
    }

    #[Test]
    public function lazy_by_id_refuses_too(): void
    {
        $this->expectException(UnsupportedMapping::class);

        iterator_count($this->harness->projection('Seat')::query()->lazyById(1));
    }

    /**
     * `chunk()`, `cursorPaginate()` and `lazy()` add an order on the key
     * when the query has none. On a composite-key projection that is
     * `order by "seats".""`, which orders by nothing at all.
     */
    #[Test]
    public function an_unordered_cursor_read_refuses(): void
    {
        $this->expectException(UnsupportedMapping::class);

        $this->harness->projection('Seat')::query()->cursorPaginate(2);
    }

    #[Test]
    public function an_ordered_cursor_read_is_fine(): void
    {
        $page = $this->harness->projection('Seat')::query()
            ->orderBy('row_letter')
            ->orderBy('seat_number')
            ->cursorPaginate(2);

        self::assertCount(2, $page->items());
    }

    /** `route('seats.show', $seat)` used to produce `/seats/`. */
    #[Test]
    public function the_route_key_is_refused_rather_than_null(): void
    {
        $this->expectException(UnsupportedMapping::class);
        $this->expectExceptionMessageMatches('/getRouteKey/');

        $this->seat()->getRouteKey();
    }

    /** And the way back: a permanent 404, via two PHP deprecations. */
    #[Test]
    public function route_binding_is_refused_rather_than_a_silent_miss(): void
    {
        $this->expectException(UnsupportedMapping::class);
        $this->expectExceptionMessageMatches('/resolveRouteBinding/');

        $this->harness->projection('Seat')::query()->getModel()->resolveRouteBinding('A');
    }

    /** Binding on a named field is answerable, so it is answered. */
    #[Test]
    public function route_binding_on_a_named_field_still_works(): void
    {
        $bound = $this->harness->projection('Seat')::query()
            ->getModel()
            ->resolveRouteBinding('B', 'row_letter');

        self::assertNotNull($bound);
        self::assertSame('B', $bound->getAttribute('row_letter'));
    }

    /**
     * A queued job stores the class and the key and looks the row up on
     * the other side. There is no key to store, and the refusal happens
     * at dispatch rather than in the worker — which is where it can still
     * be seen.
     */
    #[Test]
    public function queueing_a_composite_key_projection_refuses_at_serialisation(): void
    {
        $job = new QueuedProjectionJob($this->seat());

        $this->expectException(UnsupportedMapping::class);

        serialize($job);
    }
}
