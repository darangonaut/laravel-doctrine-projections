<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Feature;

use Darangonaut\DoctrineProjections\ProjectionsServiceProvider;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * An application with more than one database connection.
 *
 * A generated model carries a table name and nothing else, so it goes to
 * `database.default` unless told otherwise. Point Doctrine at a different
 * database and the projection reads rows belonging to something else —
 * measured before this existed: two SQLite files, an `accounts` table in
 * both, and the projection handed back the row from the wrong one without
 * a word.
 */
final class ProjectionConnectionTest extends TestCase
{
    private string $output;

    private string $laravelDatabase;

    private string $doctrineDatabase;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [ProjectionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $base = sys_get_temp_dir().'/projections-connection-'.getmypid();

        $this->output = $base.'-out';
        $this->laravelDatabase = $base.'-laravel.sqlite';
        $this->doctrineDatabase = $base.'-doctrine.sqlite';

        touch($this->laravelDatabase);
        touch($this->doctrineDatabase);

        $app['config']->set('doctrine-projections.namespace', 'Generated\\Connections');
        $app['config']->set('doctrine-projections.path', $this->output);

        $app['config']->set('database.default', 'primary');
        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->laravelDatabase,
        ]);
        $app['config']->set('database.connections.entities', [
            'driver' => 'sqlite',
            'database' => $this->doctrineDatabase,
        ]);

        $app->singleton(EntityManagerInterface::class, fn (): EntityManagerInterface => $this->entityManager());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Same table in both, different rows. Which database the model
        // reads is then visible in the answer rather than inferred.
        foreach (['primary' => 'z-laravelu@example.com', 'entities' => 'z-doctrine@example.com'] as $name => $email) {
            DB::connection($name)->statement(
                'CREATE TABLE IF NOT EXISTS accounts (id INTEGER PRIMARY KEY, email TEXT NOT NULL, name TEXT NOT NULL)',
            );
            DB::connection($name)->table('accounts')->insert(['id' => 1, 'email' => $email, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        @unlink($this->laravelDatabase);
        @unlink($this->doctrineDatabase);

        parent::tearDown();
    }

    private function entityManager(): EntityManagerInterface
    {
        $config = new Configuration;
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures/Entities']));
        $config->setProxyDir(sys_get_temp_dir().'/doctrine-projections-connection-proxies');
        $config->setProxyNamespace('DoctrineProjectionsConnectionProxies');
        $config->setMetadataCache(new ArrayAdapter);
        $config->setQueryCache(new ArrayAdapter);

        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(
            DriverManager::getConnection(
                ['driver' => 'pdo_sqlite', 'path' => $this->doctrineDatabase],
                $config,
            ),
            $config,
        );
    }

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function generatedAccount(): string
    {
        $this->command('doctrine:projections')->assertSuccessful();

        $file = $this->output.'/Account.php';

        self::assertFileExists($file);

        return File::get($file);
    }

    #[Test]
    public function a_configured_connection_lands_in_the_model(): void
    {
        config()->set('doctrine-projections.connection', 'entities');

        self::assertStringContainsString("protected \$connection = 'entities';", $this->generatedAccount());
    }

    #[Test]
    public function no_configured_connection_leaves_the_model_on_the_default(): void
    {
        self::assertStringNotContainsString('$connection', $this->generatedAccount());
    }

    /** The point of the whole thing: which rows come back. */
    #[Test]
    public function the_model_reads_the_database_doctrine_is_on(): void
    {
        config()->set('doctrine-projections.connection', 'entities');

        $file = $this->output.'/Account.php';

        $this->command('doctrine:projections')->assertSuccessful();

        require_once $file;

        $class = 'Generated\\Connections\\Account';

        self::assertTrue(is_subclass_of($class, Model::class));

        $account = $class::query()->first();

        self::assertNotNull($account);
        self::assertSame('z-doctrine@example.com', $account->getAttribute('email'));
    }

    #[Test]
    public function a_mismatch_is_reported_when_nothing_is_configured(): void
    {
        $this->command('doctrine:projections')
            ->expectsOutputToContain('different databases')
            ->assertSuccessful();
    }

    #[Test]
    public function no_mismatch_is_reported_once_the_connection_is_configured(): void
    {
        config()->set('doctrine-projections.connection', 'entities');

        $this->command('doctrine:projections')
            ->doesntExpectOutputToContain('different databases')
            ->assertSuccessful();
    }
}
