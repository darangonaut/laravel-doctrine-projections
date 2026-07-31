<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Surface\Author;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Surface\Comment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Surface\Post;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The query surface an application actually writes: aliases, joins,
 * nested eager loading, relation aggregates, grouping. None of it is
 * special to a projection, which is the point — a generated model has to
 * behave like a model.
 */
final class QuerySurfaceTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Surface', 'DifferentialSurfaceQ'.getmypid());

        $jana = new Author;
        $jana->name = 'Jana';

        $peter = new Author;
        $peter->name = 'Peter';
        $peter->deletedAt = new \DateTimeImmutable('2026-07-30 10:00:00');

        foreach ([[$jana, 'Prvý', 10], [$jana, 'Druhý', 30], [$peter, 'Tretí', 20]] as [$author, $title, $views]) {
            $post = new Post;
            $post->title = $title;
            $post->views = $views;
            $post->author = $author;
            $author->posts->add($post);

            $comment = new Comment;
            $comment->body = 'k '.$title;
            $comment->post = $post;
            $post->comments->add($comment);

            $this->harness->em()->persist($post);
            $this->harness->em()->persist($comment);
        }

        $this->harness->em()->persist($jana);
        $this->harness->em()->persist($peter);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @return class-string<Model> */
    private function author(): string
    {
        return $this->harness->projection('Author');
    }

    /** @return class-string<Model> */
    private function post(): string
    {
        return $this->harness->projection('Post');
    }

    private function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    #[Test]
    public function select_with_an_alias_and_a_raw_expression(): void
    {
        $row = $this->post()::query()
            ->select('title as heading')
            ->selectRaw('views * 2 as doubled')
            ->orderBy('views')
            ->first();

        self::assertNotNull($row);
        self::assertSame('Prvý', $row->getAttribute('heading'));
        self::assertSame(20, $this->asInt($row->getAttribute('doubled')));
    }

    #[Test]
    public function a_join_between_two_projections(): void
    {
        $rows = Capsule::connection()->table('posts')
            ->join('authors', 'authors.id', '=', 'posts.author_id')
            ->where('authors.name', 'Jana')
            ->pluck('posts.title');

        self::assertSame(['Prvý', 'Druhý'], $rows->all());
    }

    #[Test]
    public function where_relation_and_with_where_has(): void
    {
        self::assertSame(2, $this->author()::query()->whereRelation('posts', 'views', '>=', 20)->count());

        $authors = $this->author()::query()
            ->withWhereHas('posts', static fn ($q) => $q->where('views', '>=', 30))
            ->get();

        self::assertCount(1, $authors);
    }

    #[Test]
    public function nested_eager_loading_three_levels_deep(): void
    {
        $author = $this->author()::query()->with('posts.comments')->where('name', 'Jana')->first();

        self::assertNotNull($author);

        $posts = $author->getAttribute('posts');

        self::assertIsIterable($posts);

        $bodies = [];
        foreach ($posts as $post) {
            self::assertInstanceOf(Model::class, $post);
            self::assertTrue($post->relationLoaded('comments'), 'the third level has to be loaded, not lazy');

            $comments = $post->getAttribute('comments');

            self::assertIsIterable($comments);

            foreach ($comments as $comment) {
                self::assertInstanceOf(Model::class, $comment);
                $bodies[] = $comment->getAttribute('body');
            }
        }

        sort($bodies);

        self::assertSame(['k Druhý', 'k Prvý'], $bodies);
    }

    #[Test]
    public function load_missing_and_load_count(): void
    {
        $author = $this->author()::query()->where('name', 'Jana')->first();

        self::assertNotNull($author);

        $author->loadMissing('posts');
        $author->loadCount('posts');

        self::assertSame(2, $author->getAttribute('posts_count'));
    }

    #[Test]
    public function relation_aggregates(): void
    {
        $author = $this->author()::query()
            ->withMax('posts', 'views')
            ->withAvg('posts', 'views')
            ->withExists('posts')
            ->where('name', 'Jana')
            ->first();

        self::assertNotNull($author);
        self::assertSame(30, $this->asInt($author->getAttribute('posts_max_views')));
        self::assertSame(20, $this->asInt($author->getAttribute('posts_avg_views')));
        self::assertTrue((bool) $author->getAttribute('posts_exists'));
    }

    #[Test]
    public function group_by_with_having(): void
    {
        $rows = $this->post()::query()
            ->selectRaw('author_id, sum(views) as total')
            ->groupBy('author_id')
            ->havingRaw('sum(views) > ?', [25])
            ->get();

        self::assertCount(1, $rows, 'only Jana clears 25');
    }

    /**
     * A column called `deleted_at` means nothing to Eloquent unless the
     * model uses SoftDeletes — which a projection does not. Worth pinning:
     * a projection quietly hiding rows would be the same class of bug as
     * a Doctrine filter it cannot see.
     */
    #[Test]
    public function a_deleted_at_column_hides_nothing(): void
    {
        self::assertSame(2, $this->author()::query()->count());

        $peter = $this->author()::query()->where('name', 'Peter')->first();

        self::assertNotNull($peter, 'a row with deleted_at set is still a row');
        self::assertNotNull($peter->getAttribute('deleted_at'));
    }

    #[Test]
    public function a_model_that_never_changes_reports_no_changes(): void
    {
        $post = $this->post()::query()->first();

        self::assertNotNull($post);
        self::assertFalse($post->isDirty());
        self::assertSame([], $post->getChanges());
        self::assertFalse($post->wasChanged());
    }

    #[Test]
    public function refresh_reads_again_without_writing(): void
    {
        $post = $this->post()::query()->orderBy('id')->first();

        self::assertNotNull($post);

        Capsule::connection()->table('posts')->where('id', $post->getKey())->update(['views' => 999]);

        $post->refresh();

        self::assertSame(999, $post->getAttribute('views'));
    }
}
