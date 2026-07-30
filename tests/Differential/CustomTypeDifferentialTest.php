<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Custom\Invoice;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Custom\Money;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Custom\MoneyType;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A custom Doctrine type converts on the way out; a projection reads the
 * column. So the entity hands back a `Money` and the projection hands
 * back the string the database holds.
 *
 * That divergence is real and cannot be removed — Eloquent knows nothing
 * about Doctrine's type registry. What matters is that the generated
 * docblock says `string` rather than promising the value object, so the
 * mismatch is visible to static analysis instead of at runtime.
 */
final class CustomTypeDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        if (! Type::hasType(MoneyType::NAME)) {
            Type::addType(MoneyType::NAME, MoneyType::class);
        }

        $this->harness = Harness::for('Custom', 'DifferentialCustom'.getmypid());

        foreach ([['2026-001', 'EUR', 125000], ['2026-002', 'CZK', 90000]] as [$number, $currency, $cents]) {
            $invoice = new Invoice;
            $invoice->number = $number;
            $invoice->total = new Money($currency, $cents);

            $this->harness->em()->persist($invoice);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function the_column_agrees_once_the_type_has_done_its_conversion(): void
    {
        (new Compare($this->harness))->columns(Invoice::class);
    }

    #[Test]
    public function the_entity_returns_the_value_object_and_the_projection_the_column(): void
    {
        $invoice = $this->harness->em()->getRepository(Invoice::class)->findOneBy(['number' => '2026-001']);

        self::assertNotNull($invoice);
        self::assertInstanceOf(Money::class, $invoice->total);
        self::assertSame('EUR', $invoice->total->currency);

        $projection = $this->harness->projection('Invoice');
        $model = $projection::query()->where('number', '2026-001')->first();

        self::assertNotNull($model);
        self::assertSame('EUR 125000', $model->getAttribute('total'), 'the projection reads the column, not the type');
    }

    #[Test]
    public function the_docblock_promises_the_column_type_not_the_value_object(): void
    {
        $rendered = (new ProjectionGenerator(
            $this->harness->em(),
            'CustomWarnings'.getmypid(),
        ))->generate();

        $code = $rendered['Invoice']->code;

        self::assertStringContainsString('@property string $total', $code);
        self::assertStringNotContainsString('Money', $code);
    }

    #[Test]
    public function the_conversion_is_reported_at_generation(): void
    {
        $rendered = (new ProjectionGenerator(
            $this->harness->em(),
            'CustomWarnings2'.getmypid(),
        ))->generate();

        $warnings = $rendered['Invoice']->warnings;

        self::assertCount(1, $warnings);
        self::assertStringContainsString('total', $warnings[0]);
        self::assertStringContainsString('money', $warnings[0]);
    }
}
