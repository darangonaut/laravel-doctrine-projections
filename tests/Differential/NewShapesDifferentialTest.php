<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3\Listing;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3\Priority;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3\Status;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The column shapes the earlier rounds never put on a row: backed enums
 * of both kinds, `ascii_string`, `smallint`, `float`, `blob`, a
 * database-written column the entity never inserts, an embeddable with
 * no prefix and one with a prefix of its own.
 */
final class NewShapesDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Shapes3', 'DifferentialShapes3'.getmypid());

        $listing = new Listing;
        $listing->status = Status::Live;
        $listing->priority = Priority::High;
        $listing->code = 'ABC-123';
        $listing->views = 42;
        $listing->ratio = 1.5;
        $listing->thumbnail = "binary\0data";
        $listing->price->minor = 12550;
        $listing->price->currency = 'CZK';
        $listing->run->from = new DateTimeImmutable('2026-01-01 10:00:00');

        $this->harness->em()->persist($listing);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function every_column_agrees_with_the_entity(): void
    {
        (new Compare($this->harness))->columns(Listing::class);
    }

    /**
     * Both enum kinds come back as the enum, not as its backing value —
     * an `int`-backed one is the case where a wrong cast would still look
     * plausible.
     */
    #[Test]
    public function backed_enums_survive_as_enums(): void
    {
        $model = $this->harness->projection('Listing')::query()->first();

        self::assertNotNull($model);
        self::assertSame(Status::Live, $model->getAttribute('status'));
        self::assertSame(Priority::High, $model->getAttribute('priority'));
    }

    /**
     * An embeddable mapped with `columnPrefix: false` puts its columns at
     * the top level; one with a prefix of its own uses that rather than
     * the property name.
     */
    #[Test]
    public function embeddable_prefixes_are_honoured(): void
    {
        $model = $this->harness->projection('Listing')::query()->first();

        self::assertNotNull($model);
        self::assertSame(12550, $model->getAttribute('amount_minor'));
        self::assertSame('CZK', $model->getAttribute('currency'));
        self::assertNotNull($model->getAttribute('run_from'));
        self::assertNull($model->getAttribute('run_to'));
    }

    /**
     * A blob is the one built-in type whose PHP shape the projection does
     * not pin down. The entity always gets a stream; the projection gets
     * whatever the driver returns — a string on SQLite and MySQL, a
     * stream on PostgreSQL.
     *
     * The bytes match either way, which is what makes it a warning rather
     * than a bug. The first version of this test asserted `string` and
     * was green locally and on MySQL and red on PostgreSQL, which is how
     * the driver dependence turned up at all.
     */
    #[Test]
    public function a_blob_carries_the_same_bytes_whatever_shape_it_arrives_in(): void
    {
        $entity = $this->harness->em()->getRepository(Listing::class)->findAll()[0];
        $model = $this->harness->projection('Listing')::query()->first();

        self::assertNotNull($model);
        self::assertIsResource($entity->thumbnail, 'the entity side is a stream on every driver');

        rewind($entity->thumbnail);

        self::assertSame(
            stream_get_contents($entity->thumbnail),
            self::bytes($model->getAttribute('thumbnail')),
        );
    }

    private static function bytes(mixed $value): string
    {
        if (is_resource($value)) {
            rewind($value);

            return (string) stream_get_contents($value);
        }

        self::assertIsString($value);

        return $value;
    }
}
