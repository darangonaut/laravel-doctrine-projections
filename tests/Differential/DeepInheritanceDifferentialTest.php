<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\CardPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\CashPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\CorporateCardPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance\Payment;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Single table inheritance three levels deep. The middle class is what
 * the two-level fixture could never show: a `CorporateCardPayment` *is* a
 * `CardPayment`, so Doctrine returns it from `CardPayment` queries, while
 * a scope of `where('kind', 'card')` leaves it out.
 *
 * Measured before the fix: the entity returned 3 rows and the projection
 * returned 1. An undercount, with nothing to notice.
 */
final class DeepInheritanceDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('DeepInheritance', 'DifferentialDeep'.getmypid());

        foreach ([[CashPayment::class, 100], [CardPayment::class, 250], [CorporateCardPayment::class, 900], [CorporateCardPayment::class, 400]] as [$class, $amount]) {
            $payment = new $class;
            $payment->amount = $amount;

            $this->harness->em()->persist($payment);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    /**
     * @param  class-string<Payment>  $entityClass
     * @return array{list<int>, list<int>} amounts from the entity, then the projection
     */
    private function amounts(string $entityClass): array
    {
        $entities = $this->harness->em()->getRepository($entityClass)->findAll();

        $expected = [];
        foreach ($entities as $entity) {
            self::assertInstanceOf(Payment::class, $entity);
            $expected[] = $entity->amount;
        }

        $projection = $this->harness->projection(class_basename($entityClass));

        $actual = [];
        foreach ($projection::query()->orderBy('id')->get() as $model) {
            self::assertInstanceOf(Model::class, $model);
            $amount = $model->getAttribute('amount');
            self::assertIsInt($amount);
            $actual[] = $amount;
        }

        sort($expected);
        sort($actual);

        return [$expected, $actual];
    }

    #[Test]
    public function a_class_with_subclasses_returns_their_rows_too(): void
    {
        [$expected, $actual] = $this->amounts(CardPayment::class);

        self::assertSame([250, 400, 900], $expected, 'Doctrine returns corporate payments as card payments');
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function a_leaf_class_returns_only_its_own_rows(): void
    {
        [$expected, $actual] = $this->amounts(CorporateCardPayment::class);

        self::assertSame([400, 900], $expected);
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function a_sibling_branch_is_unaffected(): void
    {
        [$expected, $actual] = $this->amounts(CashPayment::class);

        self::assertSame([100], $expected);
        self::assertSame($expected, $actual);
    }

    /**
     * Laravel restores a queued model with `newQueryWithoutScopes()`, so a
     * soft-deleted one can come back. That dropped the discriminator too:
     * the id of a cash payment was null through `CardPayment::find()` and
     * a `CardPayment` holding cash payment data through a job.
     */
    #[Test]
    public function restoring_a_queued_projection_keeps_the_discriminator(): void
    {
        $card = $this->harness->projection(class_basename(CardPayment::class));
        $cash = $this->harness->projection(class_basename(CashPayment::class));

        $cashRow = $cash::query()->first();

        self::assertNotNull($cashRow);

        $cashId = $cashRow->getKey();

        self::assertNull($card::query()->find($cashId), 'find() applies the scope');
        self::assertNull(
            (new $card)->newQueryForRestoration([$cashId])->first(),
            'and restoration has to agree with it',
        );
    }

    #[Test]
    public function a_projection_of_its_own_kind_still_restores(): void
    {
        $card = $this->harness->projection(class_basename(CardPayment::class));

        $row = $card::query()->orderBy('id')->first();

        self::assertNotNull($row);

        $restored = (new $card)->newQueryForRestoration([$row->getKey()])->first();

        self::assertNotNull($restored);
        self::assertSame($row->getAttribute('amount'), $restored->getAttribute('amount'));
    }

    #[Test]
    public function the_root_still_returns_everything(): void
    {
        [$expected, $actual] = $this->amounts(Payment::class);

        self::assertSame([100, 250, 400, 900], $expected);
        self::assertSame($expected, $actual);
    }
}
