<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Versioned\Document;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `#[ORM\Version]` is a column Doctrine writes to on its own, which is
 * the only thing that makes it worth checking: the number changes without
 * anyone assigning it, so a projection reading a stale value would look
 * plausible. Optimistic locking itself does not apply here — projections
 * never write.
 */
final class VersionedDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Versioned', 'DifferentialVersioned'.getmypid());
        $this->compare = new Compare($this->harness);

        foreach (['Zmluva', 'Faktúra'] as $title) {
            $document = new Document;
            $document->title = $title;
            $document->changedAt = new DateTimeImmutable('2026-07-30 12:00:00');

            $this->harness->em()->persist($document);
        }

        $this->harness->em()->flush();
    }

    #[Test]
    public function a_freshly_written_row_agrees(): void
    {
        $this->harness->forget();

        $this->compare->columns(Document::class);
    }

    #[Test]
    public function the_projection_sees_the_version_doctrine_bumped(): void
    {
        $document = $this->harness->em()->getRepository(Document::class)->findOneBy(['title' => 'Zmluva']);

        self::assertNotNull($document);
        self::assertSame(1, $document->version);

        $document->title = 'Zmluva, dodatok';
        $this->harness->em()->flush();

        self::assertSame(2, $document->version, 'Doctrine bumps this itself');

        $this->harness->forget();

        // the point: the projection must read 2, not the 1 it was inserted with
        $this->compare->columns(Document::class);

        $projection = $this->harness->projection('Document');
        $model = $projection::query()->where('title', 'Zmluva, dodatok')->first();

        self::assertNotNull($model);
        self::assertSame(2, $model->getAttribute('version'));
    }
}
