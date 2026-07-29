<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Eloquent\ReadOnlyModel;
use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lock is the package's central promise, so it is verified by actually
 * attempting every write against a real database rather than by asserting
 * that methods exist.
 *
 * Eloquent alone is enough here — no Laravel application, no Doctrine.
 */
final class ReadOnlyLockTest extends TestCase
{
    private static Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule;
        self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        $schema = self::$capsule->schema();

        $schema->create('shelves', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name');
        });

        $schema->create('books', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('shelf_id');
            $t->string('title');
            $t->unsignedInteger('page_count')->default(0);
            $t->timestamp('touched_at')->nullable();
        });

        $schema->create('genres', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name');
        });

        $schema->create('book_genre', function (Blueprint $t): void {
            $t->unsignedInteger('book_id');
            $t->unsignedInteger('genre_id');
        });
    }

    protected function setUp(): void
    {
        $db = self::$capsule->getConnection();
        $db->table('book_genre')->delete();
        $db->table('books')->delete();
        $db->table('genres')->delete();
        $db->table('shelves')->delete();

        $db->table('shelves')->insert(['id' => 1, 'name' => 'Fiction']);
        $db->table('genres')->insert([['id' => 1, 'name' => 'Novel'], ['id' => 2, 'name' => 'Essay']]);
        $db->table('books')->insert(['id' => 1, 'shelf_id' => 1, 'title' => 'Original', 'page_count' => 100]);
        $db->table('book_genre')->insert(['book_id' => 1, 'genre_id' => 1]);
    }

    #[Test]
    public function reading_is_untouched(): void
    {
        $book = ProjectedBook::with(['shelf', 'genres'])->findOrFail(1);

        self::assertSame('Original', $book->title);
        self::assertSame('Fiction', $book->shelf->name);
        self::assertSame(['Novel'], $book->genres->pluck('name')->all());
        self::assertSame(1, ProjectedBook::query()->where('page_count', '>', 50)->count());
        self::assertSame(1, ProjectedShelf::withCount('books')->findOrFail(1)->books_count);
    }

    /** @return iterable<string, array{callable}> */
    public static function writeAttempts(): iterable
    {
        // instance level — caught by model events
        yield 'save' => [function (): void {
            $b = ProjectedBook::findOrFail(1);
            $b->title = 'Hacked';
            $b->save();
        }];
        yield 'model update' => [fn () => ProjectedBook::findOrFail(1)->update(['title' => 'Hacked'])];
        yield 'delete' => [fn () => ProjectedBook::findOrFail(1)->delete()];
        yield 'create' => [fn () => ProjectedBook::create(['shelf_id' => 1, 'title' => 'Hacked'])];
        yield 'forceCreate' => [fn () => ProjectedBook::forceCreate(['shelf_id' => 1, 'title' => 'Hacked'])];
        yield 'firstOrCreate' => [fn () => ProjectedBook::firstOrCreate(['title' => 'Hacked'], ['shelf_id' => 1])];
        yield 'updateOrCreate' => [fn () => ProjectedBook::updateOrCreate(['id' => 1], ['title' => 'Hacked'])];

        // builder level — no events fire, ReadOnlyBuilder is the only guard
        yield 'builder update' => [fn () => ProjectedBook::query()->whereKey(1)->update(['title' => 'Hacked'])];
        yield 'builder delete' => [fn () => ProjectedBook::query()->whereKey(1)->delete()];
        yield 'insert' => [fn () => ProjectedBook::insert(['shelf_id' => 1, 'title' => 'Hacked'])];
        yield 'insertGetId' => [fn () => ProjectedBook::query()->insertGetId(['shelf_id' => 1, 'title' => 'Hacked'])];
        yield 'insertOrIgnore' => [fn () => ProjectedBook::query()->insertOrIgnore(['shelf_id' => 1, 'title' => 'Hacked'])];
        yield 'upsert' => [fn () => ProjectedBook::upsert([['id' => 1, 'shelf_id' => 1, 'title' => 'Hacked']], ['id'], ['title'])];
        yield 'updateOrInsert' => [fn () => ProjectedBook::query()->updateOrInsert(['id' => 1], ['title' => 'Hacked'])];
        yield 'increment' => [fn () => ProjectedBook::query()->whereKey(1)->increment('page_count')];
        yield 'decrement' => [fn () => ProjectedBook::query()->whereKey(1)->decrement('page_count')];
        yield 'incrementOrCreate' => [fn () => ProjectedBook::query()->incrementOrCreate(['title' => 'Hacked'], 'page_count')];
        yield 'truncate' => [fn () => ProjectedBook::query()->truncate()];
        // touch() writes via toBase(), so overriding update() alone misses it
        yield 'touch' => [fn () => ProjectedBook::query()->touch('touched_at')];

        // pivot level — bypasses model events entirely
        yield 'attach' => [fn () => ProjectedBook::findOrFail(1)->genres()->attach(2)];
        yield 'detach' => [fn () => ProjectedBook::findOrFail(1)->genres()->detach()];
        yield 'sync' => [fn () => ProjectedBook::findOrFail(1)->genres()->sync([2])];
        yield 'toggle' => [fn () => ProjectedBook::findOrFail(1)->genres()->toggle([2])];
    }

    #[Test]
    #[DataProvider('writeAttempts')]
    public function every_write_path_is_refused(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('the write was not blocked');
        } catch (ReadOnlyProjection) {
            self::assertTrue(true);
        }

        // and nothing moved
        $row = self::$capsule->getConnection()->table('books')->where('id', 1)->first();
        self::assertSame('Original', $row->title);
        self::assertSame(100, (int) $row->page_count);
        self::assertNull($row->touched_at);
        self::assertSame(1, self::$capsule->getConnection()->table('books')->count());
        self::assertSame(1, self::$capsule->getConnection()->table('book_genre')->count());
    }
}

class ProjectedBook extends Model
{
    use ReadOnlyModel;

    protected $table = 'books';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<ProjectedShelf, $this> */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(ProjectedShelf::class, 'shelf_id');
    }

    /** @return BelongsToMany<ProjectedGenre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(ProjectedGenre::class, 'book_genre', 'book_id', 'genre_id');
    }
}

class ProjectedShelf extends Model
{
    use ReadOnlyModel;

    protected $table = 'shelves';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<ProjectedBook, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(ProjectedBook::class, 'shelf_id');
    }
}

class ProjectedGenre extends Model
{
    use ReadOnlyModel;

    protected $table = 'genres';

    public $timestamps = false;

    protected $guarded = [];
}
