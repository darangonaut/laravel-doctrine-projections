<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Eloquent addresses a row by one column, so a composite key cannot be
 * projected faithfully. The generated model says so with
 * `$primaryKey = null` — which then has to actually behave, so the files
 * are written out, loaded and queried rather than asserted on as strings.
 *
 * Reading is the whole point of a projection and must keep working; only
 * the operations that genuinely need a single key column are refused.
 */
final class CompositeKeyTest extends TestCase
{
    private const NAMESPACE = 'CompositeKeyFixtures';

    /** @var class-string<Model> */
    private static string $model;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->statement(
            'CREATE TABLE seats (
                row_letter VARCHAR(2) NOT NULL,
                seat_number INTEGER NOT NULL,
                occupied BOOLEAN NOT NULL,
                PRIMARY KEY (row_letter, seat_number)
            )',
        );

        $capsule->getConnection()->table('seats')->insert([
            ['row_letter' => 'A', 'seat_number' => 1, 'occupied' => 1],
            ['row_letter' => 'A', 'seat_number' => 2, 'occupied' => 0],
            ['row_letter' => 'B', 'seat_number' => 1, 'occupied' => 1],
        ]);

        $dir = sys_get_temp_dir().'/composite-projections-'.getmypid();

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
        }

        self::$model = self::projection('Seat');
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
    public function the_projection_declares_no_usable_primary_key(): void
    {
        $model = new (self::$model);

        // `getKeyName()` is declared `string` but holds the null the
        // generator emitted, so the cast is what makes this checkable
        self::assertSame('', (string) $model->getKeyName());
        self::assertFalse($model->getIncrementing());
    }

    #[Test]
    public function reading_works_in_every_shape_that_does_not_need_a_key(): void
    {
        self::assertSame(3, self::$model::query()->count());
        self::assertSame(2, self::$model::query()->where('row_letter', 'A')->count());
        self::assertSame(
            [1, 2],
            self::$model::query()->where('row_letter', 'A')->orderBy('seat_number')->pluck('seat_number')->all(),
            'casts still apply — these are ints, not strings',
        );
    }

    #[Test]
    public function a_row_can_be_addressed_by_its_key_columns(): void
    {
        $occupied = self::$model::query()
            ->where(['row_letter' => 'A', 'seat_number' => 2])
            ->pluck('occupied')
            ->all();

        self::assertSame([false], $occupied);
    }

    /**
     * Without the guard this produced `where seats. = 1`, so the caller
     * got `no such column: seats.` plus a PHP deprecation from inside
     * Eloquent — neither of which says what is wrong.
     */
    #[Test]
    public function find_refuses_with_an_explanation_rather_than_broken_sql(): void
    {
        $this->expectException(UnsupportedMapping::class);
        $this->expectExceptionMessage('composite primary key');

        self::$model::query()->find(1);
    }

    #[Test]
    public function find_many_refuses_the_same_way(): void
    {
        $this->expectException(UnsupportedMapping::class);

        self::$model::query()->findMany([1, 2]);
    }

    /**
     * Laravel's delete() checks for a primary key before firing any event,
     * so the trait's `deleting` hook never ran and the write was refused
     * with LogicException — the right outcome for the wrong reason, and
     * not the exception the package promises.
     */
    #[Test]
    public function deleting_is_refused_as_a_read_only_violation(): void
    {
        $seat = self::$model::query()->first();

        self::assertNotNull($seat);

        try {
            $seat->delete();
            self::fail('delete() should have been refused');
        } catch (ReadOnlyProjection) {
            // expected
        }

        self::assertSame(3, Capsule::connection()->table('seats')->count());
    }

    /**
     * `getKey()` used to answer null for every row, and Eloquent believes
     * that answer: `is()` said two different seats were the same model,
     * `unique()` turned three rows into none, `contains()` found a row
     * that was not there, and `fresh()` on B1 handed back A1. All silent.
     */
    #[Test]
    public function operations_that_identify_a_row_by_key_are_refused(): void
    {
        $seats = self::$model::query()->orderBy('row_letter')->orderBy('seat_number')->get();

        self::assertCount(3, $seats, 'reading the rows is unaffected');

        $operations = [
            'getKey' => static fn () => $seats->first()?->getKey(),
            'is' => static fn () => $seats->first()?->is($seats->last()),
            'unique' => static fn () => $seats->unique(),
            'modelKeys' => static fn () => $seats->modelKeys(),
            'diff' => static fn () => $seats->diff($seats->take(1)),
            'fresh' => static fn () => $seats->last()?->fresh(),
        ];

        foreach ($operations as $label => $operation) {
            try {
                $operation();
                self::fail("{$label}() should have been refused");
            } catch (UnsupportedMapping) {
                // expected
            }
        }
    }

    #[Test]
    public function bulk_writes_are_refused_and_the_rows_are_untouched(): void
    {
        try {
            self::$model::query()->update(['occupied' => 0]);
            self::fail('update() should have been refused');
        } catch (ReadOnlyProjection) {
            // expected
        }

        self::assertSame(2, Capsule::connection()->table('seats')->where('occupied', 1)->count());
    }
}
