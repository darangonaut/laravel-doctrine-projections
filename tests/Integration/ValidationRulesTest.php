<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Tests\Differential\Harness;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CardPayment;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance\CashPayment;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidationFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `exists:` and `unique:` take a model only to read its table and
 * connection off it. The query they then build is a plain one against
 * that table — no model, no global scopes.
 *
 * On a single-table-inheritance projection that means `exists:CardPayment,id`
 * accepts a cash payment's id: valid input as far as the request is
 * concerned, and a 404 in the controller one line later.
 *
 * There is no hook to fix this from a model, so this test pins the
 * boundary rather than a fix — including the way out, which is to put
 * the discriminator in the rule. If a future Laravel starts applying
 * scopes here, the first assertion fails and the README paragraph can go.
 */
final class ValidationRulesTest extends TestCase
{
    private Harness $harness;

    private ValidationFactory $validator;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Inheritance', 'Validation'.getmypid());

        foreach ([[CardPayment::class, 100], [CashPayment::class, 200]] as [$class, $amount]) {
            $payment = new $class;

            $this->harness->em()->getClassMetadata($class)->setFieldValue($payment, 'amount', $amount);
            $this->harness->em()->persist($payment);
        }

        $this->harness->em()->flush();
        $this->harness->forget();

        $resolver = Model::getConnectionResolver();

        self::assertNotNull($resolver);

        $this->validator = new ValidationFactory(
            new Translator(new ArrayLoader, 'en'),
            new Container,
        );

        $this->validator->setPresenceVerifier(new DatabasePresenceVerifier($resolver));
    }

    private function row(string $projection): Model
    {
        $model = $this->harness->projection($projection)::query()->first();

        self::assertNotNull($model);

        return $model;
    }

    /** @param array<string, mixed> $rules */
    private function passes(mixed $value, array $rules): bool
    {
        return $this->validator->make(['payment' => $value], $rules)->passes();
    }

    #[Test]
    public function exists_accepts_a_sibling_subclass_row(): void
    {
        $card = $this->harness->projection('CardPayment');

        self::assertTrue(
            $this->passes($this->row('CashPayment')->getKey(), ['payment' => 'exists:'.$card.',id']),
            'if this now fails, Laravel applies scopes here and the README caveat is obsolete',
        );

        // and the projection itself does not — which is the whole gap
        self::assertNull(
            $this->harness->projection('CardPayment')::query()->find($this->row('CashPayment')->getKey()),
        );
    }

    /** Naming the discriminator in the rule closes it. */
    #[Test]
    public function adding_the_discriminator_to_the_rule_closes_the_gap(): void
    {
        $card = $this->harness->projection('CardPayment');

        self::assertFalse($this->passes(
            $this->row('CashPayment')->getKey(),
            ['payment' => 'exists:'.$card.',id,kind,card'],
        ));

        self::assertTrue($this->passes(
            $this->row('CardPayment')->getKey(),
            ['payment' => 'exists:'.$card.',id,kind,card'],
        ));
    }

    /** An unscoped projection has no gap to begin with. */
    #[Test]
    public function exists_on_an_unscoped_projection_behaves_normally(): void
    {
        $payment = $this->harness->projection('Payment');

        self::assertTrue($this->passes($this->row('CardPayment')->getKey(), ['payment' => 'exists:'.$payment.',id']));
        self::assertFalse($this->passes(9999, ['payment' => 'exists:'.$payment.',id']));
    }
}
