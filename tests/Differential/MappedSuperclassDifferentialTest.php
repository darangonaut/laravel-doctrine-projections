<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Carbon\CarbonImmutable;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Superclass\Article;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Superclass\Author;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A mapped superclass has columns but no table. It gets no projection of
 * its own — there would be nothing to select from — while everything
 * extending it has to carry its columns, casts included.
 */
final class MappedSuperclassDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Superclass', 'DifferentialSuperclass'.getmypid());
        $this->compare = new Compare($this->harness);

        $author = new Author;
        $author->name = 'Jana';
        $author->createdBy = 'import';

        $article = new Article;
        $article->title = 'O projekciách';
        $article->author = $author;

        $unattributed = new Article;
        $unattributed->title = 'Bez autora záznamu';
        $unattributed->author = $author;

        $this->harness->em()->persist($author);
        $this->harness->em()->persist($article);
        $this->harness->em()->persist($unattributed);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function the_superclass_gets_no_projection_of_its_own(): void
    {
        $this->expectExceptionMessage('No projection generated for Auditable');

        $this->harness->projection('Auditable');
    }

    #[Test]
    public function inherited_columns_agree_on_both_entities(): void
    {
        $this->compare->columns(Author::class);
        $this->compare->columns(Article::class);
    }

    #[Test]
    public function an_inherited_column_keeps_its_cast(): void
    {
        $projection = $this->harness->projection('Article');
        $article = $projection::query()->where('title', 'O projekciách')->first();

        self::assertNotNull($article);
        self::assertInstanceOf(CarbonImmutable::class, $article->getAttribute('created_at'));
        self::assertNull($article->getAttribute('created_by'), 'nullable inherited column');
    }

    #[Test]
    public function an_association_declared_on_the_child_still_works(): void
    {
        $this->compare->associations(Article::class);
    }
}
