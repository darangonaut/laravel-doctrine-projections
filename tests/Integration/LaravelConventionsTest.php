<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Tests\Differential\Harness;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Casts\Reading;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Surface\Author;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Surface\Post;
use Darangonaut\DoctrineProjections\Tests\Support\QueuedProjectionJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of Laravel that find a model by convention, or that hand it
 * to something else: policies, eager loading, aggregates, pagination,
 * queues, morph maps, soft deletes bolted on in a subclass.
 *
 * None of this is generated code — it is Eloquent doing what Eloquent
 * does to a model that happens to be read-only and, half the time,
 * scoped. Which is exactly why it is worth running rather than assuming.
 */
final class LaravelConventionsTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Surface', 'Conventions'.getmypid());

        $author = new Author;
        $author->name = 'Jana';

        $this->harness->em()->persist($author);

        for ($i = 0; $i < 3; $i++) {
            $post = new Post;
            $post->title = 'prispevok '.$i;
            $post->author = $author;

            $this->harness->em()->persist($post);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function author(): Model
    {
        $author = $this->harness->projection('Author')::query()->first();

        self::assertNotNull($author);

        return $author;
    }

    #[Test]
    public function eager_loading_and_aggregates_work(): void
    {
        $query = $this->harness->projection('Author')::query();

        self::assertSame(3, (clone $query)->withCount('posts')->first()?->getAttribute('posts_count'));

        $posts = (clone $query)->with('posts')->first()?->getAttribute('posts');

        self::assertIsIterable($posts);
        self::assertCount(3, $posts);
        self::assertSame(1, (clone $query)->has('posts')->count());
        self::assertSame(0, (clone $query)->doesntHave('posts')->count());
    }

    #[Test]
    public function pagination_reports_the_right_totals(): void
    {
        Paginator::currentPageResolver(static fn (): int => 1);

        $page = $this->harness->projection('Post')::query()->paginate(2);

        self::assertSame(3, $page->total());
        self::assertCount(2, $page->items());
        self::assertSame(2, $page->lastPage());
    }

    /**
     * `SerializesModels` stores loaded relations alongside the key and
     * loads them again on the way back, so a job about an author still
     * knows about their posts.
     */
    #[Test]
    public function a_queued_job_keeps_its_loaded_relations(): void
    {
        $author = $this->harness->projection('Author')::query()->with('posts')->first();

        self::assertNotNull($author);

        $job = unserialize(serialize(new QueuedProjectionJob($author)));

        self::assertInstanceOf(QueuedProjectionJob::class, $job);
        self::assertSame(['posts'], array_keys($job->model->getRelations()));

        $posts = $job->model->getAttribute('posts');

        self::assertIsIterable($posts);
        self::assertCount(3, $posts);
    }

    /**
     * A projection's class name is build output — the namespace is a
     * config value. Storing it raw in a morph column ties the data to
     * that value; a morph map gives it a name of its own.
     */
    #[Test]
    public function a_morph_map_gives_the_projection_a_stable_name(): void
    {
        $projection = $this->harness->projection('Author');

        self::assertSame($projection, $this->author()->getMorphClass());

        Relation::enforceMorphMap(['author' => $projection]);

        try {
            self::assertSame('author', $this->author()->getMorphClass());
        } finally {
            Relation::morphMap([], false);
        }
    }

    /**
     * Soft deletes bolted on in a subclass — the only way an application
     * can add them, since the generated file is build output.
     *
     * Reading works: the scope is just another `where`. All three write
     * paths are refused, including `restore()`, which is a `save()`
     * wearing a different name.
     */
    #[Test]
    public function soft_deletes_in_a_subclass_read_but_do_not_write(): void
    {
        $name = 'TrashableAuthor'.getmypid();

        if (! class_exists($name)) {
            eval(sprintf(
                'class %s extends \\%s { use \\Illuminate\\Database\\Eloquent\\SoftDeletes; }',
                $name,
                $this->harness->projection('Author'),
            ));
        }

        self::assertTrue(is_subclass_of($name, Model::class));

        $author = $name::query()->first();

        self::assertNotNull($author, 'the soft-delete scope must not hide a live row');

        foreach (['delete', 'restore', 'forceDelete'] as $write) {
            self::refusesWrite($author, $write);
        }
    }

    /**
     * The method name is a variable on purpose: `restore()` comes from
     * the trait the eval'd subclass uses, and static analysis has no
     * class to find it on.
     */
    private static function refusesWrite(object $model, string $method): void
    {
        try {
            $model->{$method}();

            self::fail($method.'() should have been refused');
        } catch (ReadOnlyProjection) {
            // expected
        }
    }

    /** JSON column operators go to the driver, not through the cast. */
    #[Test]
    public function json_column_operators_work(): void
    {
        $harness = Harness::for('Casts', 'ConventionsJson'.getmypid());

        $reading = new Reading;
        $reading->counter = '1';
        $reading->amount = '1.0000';
        $reading->meta = ['unit' => 'C', 'tags' => ['a', 'b']];

        $harness->em()->persist($reading);
        $harness->em()->flush();
        $harness->forget();

        $query = $harness->projection('Reading')::query();

        self::assertSame(1, (clone $query)->where('meta->unit', 'C')->count());
        self::assertSame(0, (clone $query)->where('meta->unit', 'F')->count());
        self::assertSame(1, (clone $query)->whereJsonContains('meta->tags', 'a')->count());
        self::assertSame(1, (clone $query)->whereJsonLength('meta->tags', 2)->count());
    }
}
