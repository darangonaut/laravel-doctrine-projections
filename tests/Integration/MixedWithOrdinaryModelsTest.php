<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Tests\Differential\Harness;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CardPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CashPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A hand-written model of the application's own, with a relation onto a
 * projection.
 *
 * The related class is a static rather than a literal because the
 * projections in this suite are generated into a per-process namespace.
 */
final class Ledger extends Model
{
    /** @var class-string<Model> */
    public static string $related = Model::class;

    protected $table = 'payments';

    public $timestamps = false;

    /** @return HasMany<Model, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(self::$related, 'id', 'id');
    }
}

/**
 * An application does not use projections on their own — it mixes them
 * with its own Eloquent models, and the interesting question is whether
 * a projection keeps its inheritance scope when someone else's query is
 * the one being built.
 *
 * `whereHas()` builds the related model's query, so the scope has to
 * reach into the subquery. Without it, "ledger entries that have a card
 * payment" would match every payment.
 */
final class MixedWithOrdinaryModelsTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Inheritance', 'Mixed'.getmypid());

        foreach ([[CardPayment::class, 100], [CashPayment::class, 200]] as [$class, $amount]) {
            $payment = new $class;

            $this->harness->em()->getClassMetadata($class)->setFieldValue($payment, 'amount', $amount);
            $this->harness->em()->persist($payment);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function the_scope_reaches_into_a_where_has_subquery(): void
    {
        Ledger::$related = $this->harness->projection('CardPayment');

        $query = Ledger::query()->whereHas('cards');

        // quote-agnostic: MySQL uses backticks where SQLite uses quotes
        self::assertMatchesRegularExpression('/\bkind\b.{0,3}= \?/', $query->toSql());
        self::assertSame(['card'], $query->getBindings());
        self::assertSame(1, $query->count(), 'the cash payment must not match');
    }

    /**
     * And the other way: an ordinary model reached through a projection's
     * relation is not scoped by anything, which is right — the scope
     * belongs to the projection, not to the join.
     */
    #[Test]
    public function an_unscoped_projection_matches_every_row(): void
    {
        self::assertSame(2, $this->harness->projection('Payment')::query()->count());
        self::assertSame(2, Ledger::query()->count());
    }
}
