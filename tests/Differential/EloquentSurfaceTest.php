<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\CardPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\CashPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\CorporateCardPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of Eloquent an application actually touches, over a
 * projection that carries a global scope. A scope is the thing most
 * likely to be skipped by a code path nobody thought about — the queued
 * job restore already was.
 */
final class EloquentSurfaceTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('DeepInheritance', 'DifferentialSurface'.getmypid());

        foreach ([[CashPayment::class, 100], [CardPayment::class, 250], [CorporateCardPayment::class, 900]] as [$class, $amount]) {
            $payment = new $class;
            $payment->amount = $amount;

            $this->harness->em()->persist($payment);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /** @return class-string<Model> */
    private function card(): string
    {
        return $this->harness->projection('CardPayment');
    }

    #[Test]
    public function chunking_respects_the_scope(): void
    {
        $seen = [];

        $this->card()::query()->orderBy('id')->chunk(1, static function ($rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = $row->getAttribute('amount');
            }
        });

        self::assertSame([250, 900], $seen, 'the cash payment must not appear');
    }

    #[Test]
    public function cursor_and_lazy_respect_the_scope(): void
    {
        $cursor = [];
        foreach ($this->card()::query()->orderBy('id')->cursor() as $row) {
            $cursor[] = $row->getAttribute('amount');
        }

        $lazy = [];
        foreach ($this->card()::query()->orderBy('id')->lazy() as $row) {
            $lazy[] = $row->getAttribute('amount');
        }

        self::assertSame([250, 900], $cursor);
        self::assertSame([250, 900], $lazy);
    }

    /**
     * Outside HTTP there is no request to read a page number from, so the
     * resolvers are set here rather than letting the paginator reach into
     * a container this harness does not have.
     */
    #[Test]
    public function pagination_counts_only_the_scoped_rows(): void
    {
        Paginator::currentPageResolver(static fn (): int => 1);
        Paginator::currentPathResolver(static fn (): string => 'http://localhost');

        $page = $this->card()::query()->orderBy('id')->paginate(1);

        self::assertSame(2, $page->total(), 'the count query has to carry the scope too');
        self::assertCount(1, $page->items());

        $simple = $this->card()::query()->orderBy('id')->simplePaginate(1);

        self::assertCount(1, $simple->items());
    }

    #[Test]
    public function route_model_binding_will_not_resolve_a_row_from_another_subclass(): void
    {
        $cash = $this->harness->projection('CashPayment')::query()->first();

        self::assertNotNull($cash);

        $model = new ($this->card());

        self::assertNull(
            $model->resolveRouteBinding($cash->getKey()),
            'a cash payment id must not resolve as a card payment',
        );

        $card = $this->card()::query()->first();

        self::assertNotNull($card);
        self::assertNotNull($model->resolveRouteBinding($card->getKey()));
    }

    #[Test]
    public function replicate_produces_a_copy_that_still_refuses_to_be_saved(): void
    {
        $card = $this->card()::query()->first();

        self::assertNotNull($card);

        $copy = $card->replicate();

        self::assertInstanceOf(Model::class, $copy, 'replicate itself must not throw');
        self::assertFalse($copy->exists);

        $this->expectException(ReadOnlyProjection::class);

        $copy->save();
    }

    #[Test]
    public function to_array_and_to_json_carry_the_columns(): void
    {
        $card = $this->card()::query()->orderBy('id')->first();

        self::assertNotNull($card);

        $array = $card->toArray();

        self::assertSame(250, $array['amount'] ?? null);
        self::assertArrayHasKey('kind', $array, 'the discriminator is a column like any other');

        $json = json_decode($card->toJson(), true);

        self::assertIsArray($json);
        self::assertSame(250, $json['amount'] ?? null);
    }
}
