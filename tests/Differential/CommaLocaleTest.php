<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Casts\Reading;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under a locale whose decimal separator is a comma, `12.5` prints as
 * `12,5` anywhere a number reaches string conversion through a
 * locale-aware path. Two places would matter: the generated source, and
 * the values read back through the projection.
 *
 * PHP 8 made float-to-string locale-independent, so most of the old
 * hazard is gone — but `printf('%f')` and `localeconv()`-driven code are
 * not, and neither is any library in the read path. Cheap to check, and
 * the failure it would cause (a decimal column silently reading as
 * `12,5000`) is the kind that only shows up on someone else's server.
 */
final class CommaLocaleTest extends TestCase
{
    private string|false $original = false;

    protected function setUp(): void
    {
        $this->original = setlocale(LC_ALL, '0');

        if (setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'de_DE@euro', 'German_Germany') === false) {
            self::markTestSkipped('no comma-decimal locale installed');
        }

        if (localeconv()['decimal_point'] !== ',') {
            self::markTestSkipped('the locale installed here still uses a dot');
        }
    }

    protected function tearDown(): void
    {
        if (is_string($this->original)) {
            setlocale(LC_ALL, $this->original);
        }
    }

    private function generate(): string
    {
        $code = '';

        foreach ((new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Casts'),
            'LocaleProjections',
        ))->generate() as $projection) {
            $code .= $projection->code;
        }

        return $code;
    }

    #[Test]
    public function the_generated_source_does_not_depend_on_the_locale(): void
    {
        $comma = $this->generate();

        setlocale(LC_ALL, 'C');

        self::assertSame($this->generate(), $comma);
    }

    #[Test]
    public function reading_still_agrees_with_the_entity(): void
    {
        $harness = Harness::for('Casts', 'DifferentialLocale'.getmypid());

        $reading = new Reading;
        $reading->counter = '9007199254740993';
        $reading->amount = '12.5000';
        $reading->meta = ['unit' => 'C'];

        $harness->em()->persist($reading);
        $harness->em()->flush();
        $harness->forget();

        (new Compare($harness))->columns(Reading::class);

        $model = $harness->projection('Reading')::query()->first();

        self::assertNotNull($model);

        // Not an exact string: how many places a driver keeps is its own
        // business — SQLite hands back `12.5` where MySQL pads to the
        // declared scale. The separator is what this test is about.
        $amount = $model->getAttribute('amount');

        self::assertIsString($amount);
        self::assertStringNotContainsString(',', $amount, 'the decimal picked up the locale separator');
        self::assertStringContainsString('.', $amount);
    }
}
