<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections;

use Darangonaut\DoctrineProjections\Console\DiffCommand;
use Darangonaut\DoctrineProjections\Console\GenerateProjectionsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The package deliberately does not build an EntityManager — it resolves
 * whatever the application already bound to EntityManagerInterface,
 * whether that is laravel-doctrine/orm or hand-rolled wiring.
 */
final class ProjectionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/doctrine-projections.php', 'doctrine-projections');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/doctrine-projections.php' => config_path('doctrine-projections.php'),
        ], 'doctrine-projections-config');

        $commands = [GenerateProjectionsCommand::class];

        if (config('doctrine-projections.diff.enabled', true)) {
            $commands[] = DiffCommand::class;
        }

        $this->commands($commands);
    }
}
