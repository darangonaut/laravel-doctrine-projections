<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\NumericDiscriminator\Event;
use Darangonaut\DoctrineProjections\Tests\Fixtures\NumericDiscriminator\Login;
use Darangonaut\DoctrineProjections\Tests\Fixtures\NumericDiscriminator\Purchase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The discriminator is an integer column, and the generated scope binds
 * the value as a string. Whether that matches is the driver's business —
 * SQLite has type affinity, MySQL coerces, PostgreSQL is the strict one —
 * so this runs against all three rather than being reasoned about.
 */
final class NumericDiscriminatorDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('NumericDiscriminator', 'DifferentialNumeric'.getmypid());

        foreach ([[Login::class, 'jana'], [Login::class, 'peter'], [Purchase::class, 'sam']] as [$class, $actor]) {
            $event = new $class;
            $event->actor = $actor;

            $this->harness->em()->persist($event);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @param class-string<Event> $entityClass */
    private function counts(string $entityClass): void
    {
        $projection = $this->harness->projection(class_basename($entityClass));

        self::assertSame(
            count($this->harness->em()->getRepository($entityClass)->findAll()),
            $projection::query()->count(),
            class_basename($entityClass).' disagrees on how many rows it has',
        );
    }

    #[Test]
    public function a_subclass_is_scoped_correctly_on_this_driver(): void
    {
        $this->counts(Login::class);
        $this->counts(Purchase::class);
    }

    #[Test]
    public function the_root_returns_everything(): void
    {
        $this->counts(Event::class);
    }

    #[Test]
    public function the_scoped_rows_are_the_right_ones(): void
    {
        $login = $this->harness->projection('Login');

        self::assertSame(
            ['jana', 'peter'],
            $login::query()->orderBy('actor')->pluck('actor')->all(),
        );
    }
}
