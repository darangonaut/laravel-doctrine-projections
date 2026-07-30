<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The discriminator scope is only worth anything if it actually filters,
 * so the generated code is written out, loaded and queried against a real
 * table holding rows of every subclass.
 *
 * Asserting on the emitted string would have passed just as happily while
 * the scope did nothing.
 */
final class InheritanceScopeTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('payments', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kind');
            $t->unsignedInteger('amount');
            $t->string('last_four')->nullable();
            $t->string('received_by')->nullable();
        });

        // Inserted one by one on purpose: a batch insert takes its column
        // list from the first row, which would silently drop received_by.
        foreach ([
            ['kind' => 'card', 'amount' => 100, 'last_four' => '4242', 'received_by' => null],
            ['kind' => 'card', 'amount' => 250, 'last_four' => '1881', 'received_by' => null],
            ['kind' => 'cash', 'amount' => 500, 'last_four' => null, 'received_by' => 'Jana'],
        ] as $row) {
            $capsule->getConnection()->table('payments')->insert($row);
        }

        // Generate into a namespace of its own and load the files, so the
        // test exercises the emitted code rather than a hand-written copy.
        self::$dir = sys_get_temp_dir().'/projections-sti-'.getmypid();
        @mkdir(self::$dir, 0o755, true);

        $projections = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Inheritance'),
            __NAMESPACE__.'\\Sti',
        ))->generate();

        foreach ($projections as $name => $projection) {
            $file = self::$dir.'/'.$name.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;
        }
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$dir.'/*.php') ?: []);
        @rmdir(self::$dir);
    }

    /**
     * The classes are written out and required at runtime, so their names
     * only become known to PHP — never to static analysis. This is the one
     * boundary where the type has to be asserted rather than inferred.
     *
     * @return class-string<Model>
     */
    private static function projection(string $name): string
    {
        $class = __NAMESPACE__.'\\Sti\\'.$name;

        self::assertTrue(class_exists($class), $class.' was not generated');
        self::assertTrue(is_subclass_of($class, Model::class));

        /** @var class-string<Model> */
        return $class;
    }

    #[Test]
    public function a_subclass_sees_only_its_own_rows(): void
    {
        $card = self::projection('CardPayment');
        $cash = self::projection('CashPayment');

        self::assertSame(2, $card::query()->count());
        self::assertSame(1, $cash::query()->count());
        self::assertSame([100, 250], $card::query()->orderBy('amount')->pluck('amount')->all());
        self::assertSame(['Jana'], $cash::query()->pluck('received_by')->all());
    }

    #[Test]
    public function the_root_sees_every_row(): void
    {
        $payment = self::projection('Payment');

        self::assertSame(3, $payment::query()->count());
    }

    #[Test]
    public function the_scope_survives_find_and_aggregates(): void
    {
        $card = self::projection('CardPayment');
        $cashRow = Capsule::connection()->table('payments')->where('kind', 'cash')->first();

        self::assertNotNull($cashRow);

        // a cash row must be invisible to CardPayment even by primary key
        self::assertNull($card::query()->find($cashRow->id));
        self::assertEquals(350, $card::query()->sum('amount'));
    }
}
