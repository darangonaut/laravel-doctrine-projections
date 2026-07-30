<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An association onto an entity with a composite key needs two join
 * columns; `belongsTo` takes one. The generator used to emit
 * `belongsTo(Seat::class, 'seat_row')` and drop the second column without
 * a word — so the relation matched on the row letter alone and handed
 * back whichever seat in that row came first.
 *
 * The relation is skipped now, with a warning. The key columns are still
 * on the model, so the join can be written by hand.
 */
final class CompositeJoinColumnTest extends TestCase
{
    private const NAMESPACE = 'CompositeJoinFixtures';

    /** @var class-string<Model> */
    private static string $booking;

    private static string $code;

    /** @var list<string> */
    private static array $warnings;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $connection = $capsule->getConnection();

        $connection->statement(
            'CREATE TABLE seats (row_letter VARCHAR(2), seat_number INTEGER, occupied BOOLEAN, PRIMARY KEY (row_letter, seat_number))',
        );
        $connection->statement(
            'CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, passenger VARCHAR(80), seat_row VARCHAR(2), seat_no INTEGER)',
        );

        $connection->table('seats')->insert([
            ['row_letter' => 'A', 'seat_number' => 1, 'occupied' => 1],
            ['row_letter' => 'A', 'seat_number' => 2, 'occupied' => 1],
        ]);

        // deliberately seat A2, so matching on the row alone would return A1
        $connection->table('bookings')->insert([
            ['id' => 1, 'passenger' => 'Jana', 'seat_row' => 'A', 'seat_no' => 2],
        ]);

        $dir = sys_get_temp_dir().'/composite-join-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ((new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Composite'),
            self::NAMESPACE,
        ))->generate() as $projection) {
            $file = $dir.'/'.$projection->className.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;

            if ($projection->className === 'Booking') {
                self::$code = $projection->code;
                self::$warnings = $projection->warnings;
            }
        }

        self::$booking = self::projection('Booking');
    }

    /** @return class-string<Model> */
    private static function projection(string $name): string
    {
        $class = self::NAMESPACE.'\\'.$name;

        self::assertTrue(class_exists($class), $class.' was not generated');
        self::assertTrue(is_subclass_of($class, Model::class));

        /** @var class-string<Model> */
        return $class;
    }

    #[Test]
    public function the_relation_is_skipped_rather_than_matched_on_one_column(): void
    {
        self::assertStringNotContainsString('belongsTo(Seat::class', self::$code);
        self::assertStringNotContainsString('public function seat(', self::$code);
    }

    #[Test]
    public function the_reason_is_reported(): void
    {
        self::assertCount(1, self::$warnings);
        self::assertStringContainsString('2 columns', self::$warnings[0]);
        self::assertStringContainsString('belongsTo cannot express', self::$warnings[0]);
    }

    /**
     * Skipping the relation must not hide the data: both key columns stay
     * readable so the join can be written at the call site.
     */
    #[Test]
    public function both_key_columns_remain_on_the_model(): void
    {
        $booking = self::$booking::query()->find(1);

        self::assertNotNull($booking);
        self::assertSame('A', $booking->getAttribute('seat_row'));
        self::assertSame(2, $booking->getAttribute('seat_no'));
    }

    /**
     * `row_letter` is a VARCHAR, and the foreign key type used to be
     * hardcoded to `int` for every association.
     */
    #[Test]
    public function each_key_column_is_documented_with_the_type_it_points_at(): void
    {
        self::assertStringContainsString('@property string|null $seat_row', self::$code);
        self::assertStringContainsString('@property int|null $seat_no', self::$code);
        self::assertStringNotContainsString('$seat ', self::$code);
    }
}
