<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Feature;

use Darangonaut\DoctrineProjections\ProjectionsServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The provider decides which commands to register while booting, so the
 * config has to be in place before that — which means its own application,
 * not a config change inside a test method.
 */
final class DiffCommandDisabledTest extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('doctrine-projections.diff.enabled', false);
    }

    #[Test]
    public function the_diff_command_is_not_registered(): void
    {
        $app = $this->app;

        self::assertNotNull($app);

        $commands = array_keys($app->make(Kernel::class)->all());

        self::assertNotContains('doctrine:diff', $commands);

        // the generator is unaffected — only the optional command is off
        self::assertContains('doctrine:projections', $commands);
    }
}
