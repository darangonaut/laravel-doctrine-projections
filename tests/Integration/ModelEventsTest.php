<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Tests\Differential\Harness;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CardPayment;
use Darangonaut\DoctrineProjections\Tests\Support\RecordingObserver;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Model events, with a dispatcher present.
 *
 * The harness had none for three rounds — Capsule does not install one —
 * so every model event silently did nothing, including the four this
 * package registers as the third layer of its lock. That layer looked
 * exercised and was not.
 *
 * With a dispatcher, two things are true and both are worth pinning. Read
 * events fire, because reading is what a projection is for. Write events
 * do not, because `save()` and `delete()` refuse before Eloquent gets as
 * far as dispatching one — so the events are a backstop, not a layer
 * anything currently relies on.
 */
final class ModelEventsTest extends TestCase
{
    private Harness $harness;

    /** @var class-string<Model> */
    private string $projection;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Inheritance', 'Events'.getmypid());

        $payment = new CardPayment;
        $this->harness->em()->getClassMetadata(CardPayment::class)->setFieldValue($payment, 'amount', 100);
        $this->harness->em()->persist($payment);
        $this->harness->em()->flush();
        $this->harness->forget();

        $this->projection = $this->harness->projection('CardPayment');

        self::assertNotNull(Model::getEventDispatcher(), 'without one this test proves nothing');
    }

    protected function tearDown(): void
    {
        $this->projection::flushEventListeners();
    }

    #[Test]
    public function the_retrieved_event_fires(): void
    {
        $seen = 0;

        $this->projection::retrieved(static function () use (&$seen): void {
            $seen++;
        });

        $this->projection::query()->get();

        self::assertSame(1, $seen);
    }

    #[Test]
    public function an_observer_sees_retrieved_and_not_the_write_events(): void
    {
        RecordingObserver::reset();

        $this->projection::observe(RecordingObserver::class);

        $model = $this->projection::query()->first();

        self::assertNotNull($model);

        try {
            $model->save();
        } catch (ReadOnlyProjection) {
            // expected
        }

        self::assertSame(['retrieved'], RecordingObserver::$seen);
    }

    /**
     * The refusal comes first. If a `saving` listener ever ran here it
     * would mean a write had got further than it should before being
     * stopped.
     */
    #[Test]
    public function no_write_event_fires_because_the_refusal_is_earlier(): void
    {
        $seen = [];

        foreach (['saving', 'creating', 'updating', 'deleting'] as $event) {
            $this->projection::{$event}(static function () use (&$seen, $event): void {
                $seen[] = $event;
            });
        }

        $model = $this->projection::query()->first();

        self::assertNotNull($model);

        foreach ([fn () => $model->save(), fn () => $model->delete()] as $write) {
            try {
                $write();
                self::fail('the write should have been refused');
            } catch (ReadOnlyProjection) {
                // expected
            }
        }

        self::assertSame([], $seen);
    }

    /**
     * And a listener the application registers on a write event is not
     * silently swallowed either — it simply never gets there, which is
     * the same thing said from the other side.
     */
    #[Test]
    public function a_bulk_write_is_refused_without_events_too(): void
    {
        $seen = [];

        $this->projection::updating(static function () use (&$seen): void {
            $seen[] = 'updating';
        });

        $this->expectException(ReadOnlyProjection::class);

        try {
            $this->projection::query()->update(['amount' => 1]);
        } finally {
            self::assertSame([], $seen);
        }
    }
}
