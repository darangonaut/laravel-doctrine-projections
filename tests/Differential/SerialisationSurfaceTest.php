<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Casts\Reading;
use Darangonaut\DoctrineProjections\Tests\Support\QueuedProjectionJob;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Everything that turns a projection back into plain data: `toArray()`,
 * `toJson()`, `refresh()`, and serializing it for a queue or a cache.
 *
 * All of them go through `getAttributes()`, which flushes Eloquent's
 * cached cast objects back into the attribute array by calling each
 * cast's `set()`. The two casts this package ships used to throw there,
 * on the reasoning that a read-only model has no business being written
 * to — so `toJson()` on any projection with a `time` or `simple_array`
 * column raised ReadOnlyProjection. A read, refused.
 *
 * The lock lives on persistence, and the tests at the end check it is
 * still exactly where it was.
 */
final class SerialisationSurfaceTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Casts', 'Serialisation'.getmypid());

        $reading = new Reading;
        $reading->counter = '9007199254740993';
        $reading->amount = '12.5000';
        $reading->meta = ['unit' => 'C'];
        $reading->tags = ['dom', 'kúrenie'];

        $this->harness->em()->persist($reading);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function reading(): Model
    {
        $model = $this->harness->projection('Reading')::query()->first();

        self::assertNotNull($model);

        return $model;
    }

    #[Test]
    public function to_json_works_after_the_attributes_have_been_read(): void
    {
        $model = $this->reading();

        // reading first is what puts the cast objects in the cache; the
        // second pass is where set() gets called
        $model->toArray();

        $decoded = json_decode($model->toJson(), true);

        self::assertIsArray($decoded);
        self::assertSame(['dom', 'kúrenie'], $decoded['tags']);
        self::assertIsString($decoded['taken_at']);
        self::assertStringContainsString('1970-01-01', $decoded['taken_at']);
    }

    #[Test]
    public function get_attributes_round_trips_the_casts_to_their_stored_form(): void
    {
        $model = $this->reading();

        // read them, so the cast objects are cached
        self::assertSame(['dom', 'kúrenie'], $model->getAttribute('tags'));
        self::assertNotNull($model->getAttribute('taken_at'));

        $attributes = $model->getAttributes();

        self::assertSame('dom,kúrenie', $attributes['tags'], 'what Doctrine would have stored');
        self::assertSame('14:30:00', $attributes['taken_at']);
    }

    /** An empty list is null in the column, the way Doctrine writes it. */
    #[Test]
    public function an_empty_simple_array_round_trips_to_null(): void
    {
        $model = $this->reading();

        $model->setAttribute('tags', []);

        self::assertNull($model->getAttributes()['tags']);
    }

    #[Test]
    public function refresh_works(): void
    {
        $model = $this->reading();

        $model->toArray();

        self::assertSame(['dom', 'kúrenie'], $model->refresh()->getAttribute('tags'));
    }

    #[Test]
    public function a_projection_with_casts_can_be_queued(): void
    {
        $model = $this->reading();

        $model->toArray();

        $job = unserialize(serialize(new QueuedProjectionJob($model)));

        self::assertInstanceOf(QueuedProjectionJob::class, $job);
        self::assertSame(['dom', 'kúrenie'], $job->model->getAttribute('tags'));
    }

    /** The lock is on persistence, and it did not move. */
    #[Test]
    public function writing_is_still_refused(): void
    {
        $model = $this->reading();

        $model->setAttribute('tags', ['nieco', 'ine']);

        $this->expectException(ReadOnlyProjection::class);

        $model->save();
    }

    #[Test]
    public function bulk_writing_is_still_refused(): void
    {
        $this->expectException(ReadOnlyProjection::class);

        $this->harness->projection('Reading')::query()->update(['tags' => 'x']);
    }
}
