<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Model events are one of the lock's three layers, and Laravel ships
 * several ways to switch them off on purpose. If any write path rested on
 * events alone, this is where it would show.
 *
 * None does — `withoutEvents()` really does silence the event, and the
 * save is stopped one layer down in `ReadOnlyBuilder::update()`. That is
 * the argument for having three layers rather than one, so it is pinned
 * here where a later simplification would trip over it.
 */
final class EventBypassLockTest extends TestCase
{
    /** @var class-string<Model> */
    private static string $model;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->statement(
            'CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(180) NOT NULL)',
        );
        $capsule->getConnection()->table('accounts')->insert(['email' => 'jana@example.test']);

        $dir = sys_get_temp_dir().'/event-bypass-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ((new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Entities'),
            'EventBypassFixtures',
        ))->generate() as $projection) {
            $file = $dir.'/'.$projection->className.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;
        }

        self::$model = self::projection('Account');
    }

    /** @return class-string<Model> */
    private static function projection(string $name): string
    {
        $class = 'EventBypassFixtures\\'.$name;

        self::assertTrue(class_exists($class), $class.' was not generated');
        self::assertTrue(is_subclass_of($class, Model::class));

        /** @var class-string<Model> */
        return $class;
    }

    private function untouched(): void
    {
        self::assertSame(
            'jana@example.test',
            Capsule::connection()->table('accounts')->value('email'),
            'the row must be exactly as it was',
        );
    }

    #[Test]
    public function without_events_does_not_open_a_way_through(): void
    {
        $account = self::$model::query()->first();

        self::assertNotNull($account);
        $account->setAttribute('email', 'someone@else.test');

        try {
            Model::withoutEvents(static fn () => $account->save());
            self::fail('save() inside withoutEvents() should have been refused');
        } catch (ReadOnlyProjection) {
            // expected
        }

        $this->untouched();
    }

    #[Test]
    public function the_quiet_variants_are_refused(): void
    {
        $account = self::$model::query()->first();

        self::assertNotNull($account);
        $account->setAttribute('email', 'someone@else.test');

        foreach (['saveQuietly', 'deleteQuietly'] as $method) {
            try {
                $account->{$method}();
                self::fail($method.'() should have been refused');
            } catch (ReadOnlyProjection) {
                // expected
            }
        }

        $this->untouched();
    }

    #[Test]
    public function unguarded_mass_assignment_still_cannot_be_saved(): void
    {
        try {
            Model::unguarded(function (): void {
                $account = self::$model::query()->first();

                self::assertNotNull($account);

                $account->fill(['email' => 'someone@else.test']);
                $account->save();
            });

            self::fail('save() after fill() should have been refused');
        } catch (ReadOnlyProjection) {
            // expected
        }

        $this->untouched();
    }

    /**
     * `save()` on a model with nothing dirty issues no UPDATE, so it used
     * to return true — teaching the caller that saving a projection works
     * when it had simply done nothing. The refusal is about the attempt.
     */
    #[Test]
    public function saving_is_refused_even_when_nothing_changed(): void
    {
        $account = self::$model::query()->first();

        self::assertNotNull($account);
        self::assertFalse($account->isDirty(), 'nothing was touched');

        $this->expectException(ReadOnlyProjection::class);

        $account->save();
    }

    #[Test]
    public function push_is_refused(): void
    {
        $account = self::$model::query()->first();

        self::assertNotNull($account);

        $this->expectException(ReadOnlyProjection::class);

        $account->push();
    }
}
