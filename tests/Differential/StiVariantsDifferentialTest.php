<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants\AbstractFile;
use Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants\Folder;
use Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants\ImageFile;
use Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants\Node;
use Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants\TextFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An abstract class in the middle of a single-table hierarchy has no
 * discriminator value of its own, only subclasses. The scope was only
 * emitted for a class that had its own value, so `AbstractFile` got none
 * at all and returned every row in the table — Doctrine said 3, the
 * projection said 4, and the extra one was a folder.
 *
 * The root is the one class that legitimately stays unscoped: "every
 * node" is a real question and the root is what asks it.
 */
final class StiVariantsDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('StiVariants', 'DifferentialSti'.getmypid());

        foreach ([[TextFile::class, 'a.txt'], [TextFile::class, 'b.txt'], [ImageFile::class, 'c.png'], [Folder::class, 'docs']] as [$class, $name]) {
            $node = new $class;
            $node->name = $name;

            $this->harness->em()->persist($node);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @param class-string<Node> $entityClass */
    private function assertCountsAgree(string $entityClass, int $expected): void
    {
        $projection = $this->harness->projection(class_basename($entityClass));

        self::assertCount(
            $expected,
            $this->harness->em()->getRepository($entityClass)->findAll(),
            'the fixture is meant to produce this many',
        );

        self::assertSame($expected, $projection::query()->count(), class_basename($entityClass).' disagrees');
    }

    #[Test]
    public function an_abstract_class_in_the_middle_covers_its_subclasses_only(): void
    {
        $this->assertCountsAgree(AbstractFile::class, 3);
    }

    #[Test]
    public function the_root_still_covers_everything(): void
    {
        $this->assertCountsAgree(Node::class, 4);
    }

    #[Test]
    public function the_leaves_are_unchanged(): void
    {
        $this->assertCountsAgree(TextFile::class, 2);
        $this->assertCountsAgree(ImageFile::class, 1);
        $this->assertCountsAgree(Folder::class, 1);
    }

    /**
     * A subclass column can only be nullable in a shared table — the
     * other subclasses have nothing to put there — so the cast has to
     * tolerate null.
     */
    #[Test]
    public function a_nullable_subclass_column_reads_back(): void
    {
        $text = $this->harness->projection('TextFile');
        $row = $text::query()->first();

        self::assertNotNull($row);
        self::assertNull($row->getAttribute('size_bytes'));
        self::assertNull($row->getAttribute('encoding'));
    }
}
