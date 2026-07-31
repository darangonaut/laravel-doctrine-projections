<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Quoted\Booking;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine's escape hatch for reserved words is a name wrapped in
 * backticks in the mapping. The backticks are Doctrine's syntax, not part
 * of the name — `getTableName()` hands back `order`, and Eloquent quotes
 * it again its own way.
 *
 * Copying the backticks into `$table` would produce ``` `order` ``` inside
 * Eloquent's own quoting and query a table that does not exist. The
 * fixture uses a keyword for the table, the key and an ordinary column, so
 * every place the name is spliced into SQL is covered.
 */
final class QuotedNamesDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Quoted', 'DifferentialQuoted'.getmypid());

        foreach (['left join', 'having'] as $label) {
            $booking = new Booking;
            $booking->label = $label;
            $this->harness->em()->persist($booking);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function a_keyword_table_and_keyword_columns_read_the_same_on_both_sides(): void
    {
        (new Compare($this->harness))->columns(Booking::class);
    }

    #[Test]
    public function a_keyword_column_can_be_filtered_on(): void
    {
        $found = $this->harness->projection('Booking')::query()->where('group', 'having')->first();

        self::assertNotNull($found);
        self::assertSame('having', $found->getAttribute('group'));
    }

    #[Test]
    public function find_on_a_keyword_primary_key_works(): void
    {
        $first = $this->harness->em()->getRepository(Booking::class)->findOneBy(['label' => 'having']);

        self::assertNotNull($first);

        $projected = $this->harness->projection('Booking')::query()->find($first->id);

        self::assertNotNull($projected);
        self::assertSame('having', $projected->getAttribute('group'));
    }
}
