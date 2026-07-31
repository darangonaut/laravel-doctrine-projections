<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Casts\Reading;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Both sides turn a column into a date object, and each does it its own
 * way: Doctrine through `DateTimeImmutable::createFromFormat()` with the
 * platform's format, Eloquent through Carbon with the connection
 * grammar's. Neither is told a timezone, so both fall back to PHP's
 * default — which a Laravel application sets from `config('app.timezone')`
 * at boot.
 *
 * That shared fallback is the whole reason they agree, and it is invisible
 * in a suite that only ever runs at UTC. Every assertion here would pass
 * on a broken implementation at UTC and fail at Europe/Prague, so the
 * timezone is varied rather than assumed.
 */
final class TimezoneDifferentialTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // A leaked default timezone would quietly change how every later
        // test reads a date.
        date_default_timezone_set($this->originalTimezone);
    }

    private function seed(string $timezone): Harness
    {
        date_default_timezone_set($timezone);

        $harness = Harness::for('Casts', 'DifferentialTz'.getmypid());

        $reading = new Reading;
        $reading->counter = '9007199254740993';
        $reading->amount = '12.5000';
        $reading->meta = ['unit' => 'C'];

        // Deliberately carries an offset that is not the default zone's:
        // a value the application did not create in its own timezone.
        $reading->recordedAt = new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('America/New_York'));

        $harness->em()->persist($reading);
        $harness->em()->flush();
        $harness->forget();

        return $harness;
    }

    /** @return list<array{string}> */
    public static function timezones(): array
    {
        return [['UTC'], ['Europe/Prague'], ['America/New_York'], ['Australia/Eucla']];
    }

    #[Test]
    #[DataProvider('timezones')]
    public function every_column_agrees_whatever_the_application_timezone_is(string $timezone): void
    {
        (new Compare($this->seed($timezone)))->columns(Reading::class);
    }

    /**
     * Wall-clock agreement is not enough on its own: two dates can print
     * the same and mean different instants if their zones differ. Both
     * are checked.
     */
    #[Test]
    #[DataProvider('timezones')]
    public function the_two_sides_mean_the_same_instant(string $timezone): void
    {
        $harness = $this->seed($timezone);

        $entity = $harness->em()->getRepository(Reading::class)->findAll()[0];
        $model = $harness->projection('Reading')::query()->first();

        self::assertNotNull($model);

        foreach (['recordedAt' => 'recorded_at', 'observedAt' => 'observed_at'] as $field => $column) {
            $left = $entity->{$field};
            $right = $model->getAttribute($column);

            self::assertInstanceOf(DateTimeInterface::class, $right, $column.' did not come back as a date');

            self::assertSame(
                $left->format('Y-m-d H:i:s'),
                $right->format('Y-m-d H:i:s'),
                $column.' reads as a different wall-clock time at '.$timezone,
            );

            self::assertSame(
                $left->getTimestamp(),
                $right->getTimestamp(),
                $column.' reads as a different instant at '.$timezone,
            );
        }
    }
}
