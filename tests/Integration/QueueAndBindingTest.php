<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Tests\Differential\Harness;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CardPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CashPayment;
use Darangonaut\DoctrineProjections\Tests\Support\QueuedProjectionJob;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The same surfaces on an ordinary projection, where they can be answered
 * — and on a scoped one, so the discriminator has to survive every round
 * trip.
 *
 * A queued job and a cache entry both put the model through serialization
 * and bring something back. What comes back must still be read-only and
 * must still be scoped: a `CardPayment` that returns cash rows after a
 * trip through Redis is worse than one that never worked.
 */
final class QueueAndBindingTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Inheritance', 'Binding'.getmypid());

        foreach ([[CardPayment::class, 100], [CashPayment::class, 200], [CardPayment::class, 300]] as [$class, $amount]) {
            $payment = new $class;

            $this->harness->em()->getClassMetadata($class)->setFieldValue($payment, 'amount', $amount);
            $this->harness->em()->persist($payment);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function firstCard(): Model
    {
        $card = $this->harness->projection('CardPayment')::query()->orderBy('id')->first();

        self::assertNotNull($card);

        return $card;
    }

    #[Test]
    public function a_queued_projection_comes_back_as_the_same_row(): void
    {
        $card = $this->firstCard();

        $job = unserialize(serialize(new QueuedProjectionJob($card)));

        self::assertInstanceOf(QueuedProjectionJob::class, $job);

        $restored = $job->model;

        self::assertSame($card->getKey(), $restored->getKey());
        self::assertSame($card->getAttribute('amount'), $restored->getAttribute('amount'));
    }

    /**
     * Laravel restores a queued model with `newQueryWithoutScopes()`, so
     * the discriminator would be gone and a sibling subclass's id would
     * resolve — a job about a card payment waking up holding a cash one.
     * The generated projection overrides `newQueryForRestoration()` for
     * exactly this, and the restore then fails loudly instead.
     */
    #[Test]
    public function a_queued_projection_cannot_be_restored_as_a_sibling(): void
    {
        $cash = $this->harness->projection('CashPayment')::query()->first();

        self::assertNotNull($cash);

        $card = $this->firstCard();

        // the payload a queue would hold, with a sibling's id in it
        $identifier = new ModelIdentifier($card::class, $cash->getKey(), [], null);

        $this->expectException(ModelNotFoundException::class);

        (new QueuedProjectionJob($card))->__unserialize(['model' => $identifier]);
    }

    /** Serialization is how a cache entry round-trips too. */
    #[Test]
    public function a_cached_projection_is_still_read_only_and_still_scoped(): void
    {
        $restored = unserialize(serialize($this->firstCard()));

        self::assertInstanceOf(Model::class, $restored);
        self::assertNotSame([], $restored->getGlobalScopes(), 'the discriminator scope survived');

        $this->expectException(ReadOnlyProjection::class);

        $restored->save();
    }

    #[Test]
    public function route_binding_finds_its_own_row(): void
    {
        $card = $this->firstCard();

        $bound = $this->harness->projection('CardPayment')::query()
            ->getModel()
            ->resolveRouteBinding($card->getKey());

        self::assertNotNull($bound);
        self::assertSame($card->getKey(), $bound->getKey());
    }

    /**
     * And refuses a sibling's. Route binding goes through the model's own
     * query, so the discriminator scope applies — which is the difference
     * between a 404 and handing a cash payment to a card controller.
     */
    #[Test]
    public function route_binding_does_not_resolve_a_sibling_subclass(): void
    {
        $cash = $this->harness->projection('CashPayment')::query()->first();

        self::assertNotNull($cash);

        self::assertNull(
            $this->harness->projection('CardPayment')::query()
                ->getModel()
                ->resolveRouteBinding($cash->getKey()),
        );
    }

    #[Test]
    public function chunk_and_cursor_reads_work_on_an_ordinary_projection(): void
    {
        $seen = 0;

        $this->harness->projection('CardPayment')::query()
            ->chunkById(1, static function ($rows) use (&$seen): void {
                $seen += $rows->count();
            });

        self::assertSame(2, $seen, 'both card payments, and neither cash one');

        self::assertCount(1, $this->harness->projection('CardPayment')::query()->cursorPaginate(1)->items());
        self::assertSame(2, iterator_count($this->harness->projection('CardPayment')::query()->lazyById(1)));
    }
}
