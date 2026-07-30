<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Casts\Reading;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine returns a string for BIGINT and DECIMAL, and not out of
 * caution: an unsigned BIGINT goes past PHP_INT_MAX, and no float holds
 * an arbitrary DECIMAL. Casting to int and float silently changed the
 * value — measured on MySQL, `12345678901234.5678` came back as
 * `12345678901234.568`.
 *
 * SQLite has no real DECIMAL and rounds on the way in, so the exactness
 * of the stored value is only meaningful on a server. What holds
 * everywhere is that the two sides agree.
 */
final class NumericPrecisionDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Casts', 'DifferentialNumeric'.getmypid());

        $reading = new Reading;
        $reading->counter = '9007199254740993';   // 2^53 + 1
        $reading->amount = '12345678901234.5678';
        $reading->valid = true;
        $reading->meta = ['unit' => 'kWh'];

        $this->harness->em()->persist($reading);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function projected(string $column): mixed
    {
        $projection = $this->harness->projection('Reading');
        $model = $projection::query()->first();

        self::assertNotNull($model);

        return $model->getAttribute($column);
    }

    private function entity(): Reading
    {
        $reading = $this->harness->em()->getRepository(Reading::class)->find(1);

        self::assertInstanceOf(Reading::class, $reading);

        return $reading;
    }

    #[Test]
    public function a_bigint_stays_a_string_on_both_sides(): void
    {
        self::assertSame($this->entity()->counter, $this->projected('counter'));
        self::assertIsString($this->projected('counter'));
    }

    #[Test]
    public function a_bigint_keeps_every_digit(): void
    {
        // as an int this is representable, but the point is that nothing
        // converts it — an unsigned BIGINT would not be
        self::assertSame('9007199254740993', $this->projected('counter'));
    }

    #[Test]
    public function a_decimal_stays_a_string_on_both_sides(): void
    {
        self::assertSame($this->entity()->amount, $this->projected('amount'));
        self::assertIsString($this->projected('amount'));
    }

    #[Test]
    public function the_decimal_keeps_its_scale(): void
    {
        if (Database::driver() === 'sqlite') {
            self::markTestSkipped('SQLite has no DECIMAL; it rounds on the way in, so there is no scale left to keep.');
        }

        $amount = $this->projected('amount');

        self::assertIsString($amount);

        // four decimal places, as the mapping declares
        self::assertMatchesRegularExpression('/\.\d{4}$/', $amount);
        self::assertSame('12345678901234.5678', $amount, 'this is the digit a float lost');
    }

    #[Test]
    public function a_plain_integer_is_still_an_integer(): void
    {
        self::assertIsInt($this->projected('id'));
    }
}
