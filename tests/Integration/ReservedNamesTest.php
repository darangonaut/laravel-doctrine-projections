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
 * Two different kinds of "reserved name" meet here.
 *
 * A word reserved by SQL — a table called `order`, a column called `key` —
 * turns out to be a non-event: Doctrine strips the backticks it asked for
 * in the mapping, and Eloquent quotes identifiers on its own.
 *
 * A name reserved by *Eloquent* is the dangerous one. A column called
 * `exists` is not readable as `$flag->exists`, because PHP finds Model's
 * public `$exists` and never calls `__get` — the answer is "this row is
 * persisted", not the column, and nothing errors.
 */
final class ReservedNamesTest extends TestCase
{
    private const NAMESPACE = 'ReservedFixtures';

    /** @var class-string<Model> */
    private static string $order;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->statement(
            'CREATE TABLE "order" (id INTEGER PRIMARY KEY AUTOINCREMENT, "key" VARCHAR(40) NOT NULL, total INTEGER NOT NULL)',
        );

        $capsule->getConnection()->table('order')->insert([
            ['key' => 'ABC-1', 'total' => 250],
            ['key' => 'ABC-2', 'total' => 90],
        ]);

        $dir = sys_get_temp_dir().'/reserved-projections-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ((new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Reserved'),
            self::NAMESPACE,
        ))->generate() as $projection) {
            $file = $dir.'/'.$projection->className.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;
        }

        self::$order = self::projection('Order');
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

    /**
     * Doctrine wants reserved names backticked in the mapping, but
     * `getTableName()` hands them back clean — so the generated `$table`
     * must not carry the quoting through, or Eloquent would quote it again.
     */
    #[Test]
    public function a_reserved_table_name_arrives_unquoted(): void
    {
        self::assertSame('order', (new (self::$order))->getTable());
    }

    #[Test]
    public function reading_a_reserved_table_and_column_works(): void
    {
        self::assertSame(2, self::$order::query()->count());

        $order = self::$order::query()->where('key', 'ABC-2')->first();

        self::assertNotNull($order);
        self::assertSame(90, $order->getAttribute('total'));
        self::assertSame('ABC-2', $order->getAttribute('key'));
    }

    #[Test]
    public function a_reserved_column_is_still_documented(): void
    {
        // `key` collides with nothing on Model, so it stays a normal column
        $order = self::$order::query()->orderBy('total')->first();

        self::assertNotNull($order);
        self::assertSame('ABC-2', $order->getAttribute('key'));
    }

    /**
     * The column really is unreadable that way, which is why the generator
     * warns instead of documenting it.
     */
    #[Test]
    public function a_column_shadowing_a_model_property_is_reported_not_documented(): void
    {
        $rendered = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Shadow'),
            self::NAMESPACE.'\\Shadowed',
        ))->generate();

        $flag = $rendered['Flag'];

        self::assertStringNotContainsString('@property bool $exists', $flag->code);
        self::assertCount(1, $flag->warnings);
        self::assertStringContainsString('exists', $flag->warnings[0]);
        self::assertStringContainsString('getAttribute', $flag->warnings[0]);

        // the other columns are unaffected
        self::assertStringContainsString('@property string $name', $flag->code);
    }
}
