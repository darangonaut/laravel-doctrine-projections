<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Surface\Author;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generated directory is build output, so anything an application
 * wants to add — an accessor, a cast, a scope — has to go in a subclass.
 * The README says so; this checks that it actually works, including that
 * the subclass stays as read-only as its parent.
 */
final class ProjectionExtensibilityTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Surface', 'DifferentialExtend'.getmypid());

        $author = new Author;
        $author->name = 'jana nováková';

        $this->harness->em()->persist($author);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @return class-string<Model> */
    private function subclass(): string
    {
        $parent = $this->harness->projection('Author');
        $name = 'ExtendedAuthor'.getmypid();

        if (! class_exists($name)) {
            eval(sprintf(
                'class %s extends \\%s {
                    protected $hidden = ["deleted_at"];
                    public function getDisplayNameAttribute(): string {
                        return ucwords((string) $this->getAttribute("name"));
                    }
                    public function scopeNamed($query, string $name) {
                        return $query->where("name", $name);
                    }
                }',
                $name,
                $parent,
            ));
        }

        /** @var class-string<Model> $name */
        return $name;
    }

    #[Test]
    public function an_accessor_added_in_a_subclass_works(): void
    {
        $author = $this->subclass()::query()->first();

        self::assertNotNull($author);
        self::assertSame('Jana Nováková', $author->getAttribute('display_name'));
    }

    #[Test]
    public function a_scope_added_in_a_subclass_works(): void
    {
        $query = $this->subclass()::query();

        self::assertTrue($query->hasNamedScope('named'));

        // applied through scopes() rather than ->named(), so static
        // analysis is not asked to know a class that exists only at runtime
        $scoped = $query->scopes(['named' => ['jana nováková']]);

        self::assertInstanceOf(Builder::class, $scoped);
        self::assertNotNull($scoped->first());
    }

    /**
     * Projections declare no `$hidden` of their own — what a column means
     * to an API is the application's business, not the mapping's. Setting
     * it in a subclass has to work.
     */
    #[Test]
    public function hidden_declared_in_a_subclass_is_honoured(): void
    {
        $author = $this->subclass()::query()->first();

        self::assertNotNull($author);
        self::assertArrayNotHasKey('deleted_at', $author->toArray());
        self::assertArrayHasKey('name', $author->toArray());

        $generated = $this->harness->projection('Author')::query()->first();

        self::assertNotNull($generated);
        self::assertArrayHasKey('deleted_at', $generated->toArray(), 'the generated model hides nothing');
    }

    #[Test]
    public function the_subclass_is_still_read_only(): void
    {
        $author = $this->subclass()::query()->first();

        self::assertNotNull($author);

        $this->expectException(ReadOnlyProjection::class);

        $author->save();
    }
}
