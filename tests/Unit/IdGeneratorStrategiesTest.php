<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the key is generated decides two lines of the projection, and both
 * are silent when wrong: a string key read as an int comes back mangled
 * rather than missing.
 */
final class IdGeneratorStrategiesTest extends TestCase
{
    #[Test]
    public function a_custom_generator_on_a_string_key_yields_a_string_key(): void
    {
        $code = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Generators'),
            'GeneratorProjections',
        ))->generate()['Ticket']->code;

        self::assertStringContainsString("protected \$keyType = 'string';", $code);
        self::assertStringContainsString('public $incrementing = false;', $code);
    }

    /**
     * A sequence hands out integers, but Doctrine assigns them before the
     * insert rather than letting the column do it — `isIdGeneratorIdentity()`
     * is false. For a projection that changes nothing observable: it never
     * inserts, and the key still reads back as an int. Recorded so the
     * `$incrementing = false` in the generated file is not mistaken for a
     * mistake.
     */
    #[Test]
    public function a_sequence_key_is_an_int_key_that_does_not_claim_to_increment(): void
    {
        $code = (new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Sequences'),
            'SequenceProjections',
        ))->generate()['Invoice']->code;

        self::assertStringNotContainsString('$keyType', $code, 'an int key is Eloquent’s default');
        self::assertStringContainsString('public $incrementing = false;', $code);
    }
}
