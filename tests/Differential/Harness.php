<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Support\MappedTables;
use Darangonaut\DoctrineProjections\Support\SharedPdoDriver;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Puts Doctrine and the generated projections on one database so their
 * answers can be compared directly.
 *
 * Both sides must run on the same connection or the comparison proves
 * nothing, so this shares one PDO through the package's own
 * `SharedPdoDriver` — which the test suite otherwise never exercised.
 *
 * The schema comes from `SchemaTool`, not from hand-written DDL: a
 * differential test that compares against a table someone typed out by
 * hand is comparing against a second opinion, not against the mapping.
 */
final class Harness
{
    private readonly EntityManagerInterface $em;

    private readonly Capsule $capsule;

    /** @var array<string, class-string<Model>> short name => projection */
    private array $projections = [];

    private function __construct(string $fixtureDir, private readonly string $namespace)
    {
        $this->capsule = new Capsule;
        $this->capsule->addConnection(Database::laravelConfig());
        $this->capsule->setAsGlobal();

        // Before bootEloquent(), which is where Capsule hands whatever it
        // has to Eloquent — setting it afterwards leaves models without
        // one. Capsule installs none by default, so for three rounds no
        // model event fired at all: a whole layer of the write lock, and
        // every read event an application might hook, went unexercised.
        $this->capsule->setEventDispatcher(new Dispatcher(new Container));

        $this->capsule->bootEloquent();

        // A driver configured but unreachable would otherwise surface as a
        // confusing failure deep inside DBAL. Saying which one, and why,
        // costs one query.
        $reason = Database::unavailableReason($this->capsule->getConnection()->getPdo());

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $this->em = $this->entityManager($fixtureDir);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        // A server keeps its schema between runs, unlike sqlite :memory:.
        $this->dropEverything();
        $this->dropQualifiedTables();
        (new SchemaTool($this->em))->createSchema($metadata);

        $this->loadProjections();
    }

    public static function for(string $fixtureDir, string $namespace): self
    {
        return new self($fixtureDir, $namespace);
    }

    private function entityManager(string $fixtureDir): EntityManagerInterface
    {
        $config = new Configuration;
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures/'.$fixtureDir]));
        $config->setProxyDir(sys_get_temp_dir().'/differential-proxies');
        $config->setProxyNamespace('DifferentialProxies');
        $config->setMetadataCache(new ArrayAdapter);
        $config->setQueryCache(new ArrayAdapter);

        // Associations here are read through, so proxies are actually
        // needed — unlike the generator tests. Native lazy objects want
        // PHP 8.4; on 8.3 Doctrine falls back to symfony/var-exporter,
        // which symfony/cache already brings in.
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        // the same handle Eloquent is about to read through
        $connection = new DbalConnection(
            ['dbname' => $this->capsule->getConnection()->getDatabaseName()],
            new SharedPdoDriver(Database::dbalDriver(), $this->capsule->getConnection()->getPdo()),
            $config,
        );

        $em = new EntityManager($connection, $config);

        // Exactly what the README tells applications to do, and needed
        // here for the same reason: on a server the database is shared
        // between test classes, so without a filter Doctrine sees every
        // other fixture's tables and offers to drop them.
        $owned = MappedTables::of($em);

        $connection->getConfiguration()->setSchemaAssetsFilter(
            static fn (string $table): bool => in_array($table, $owned, true),
        );

        return $em;
    }

    /**
     * Empties the whole test database, not just this fixture's tables.
     *
     * `SchemaTool::dropSchema()` drops what the mapping declares and
     * swallows every failure, which is a bad combination on a server: two
     * fixtures both map a table called `authors`, and the one left behind
     * by an earlier test class is still referenced by *its* `articles`.
     * MySQL refuses the DROP, SchemaTool ignores the refusal, and the
     * CREATE that follows fails with "table already exists" — in a test
     * class that has nothing to do with either fixture.
     *
     * SQLite runs in memory and never saw it, so the suite was green
     * locally and order-dependent everywhere else. The database here is
     * the harness's own, so emptying it is both safe and the only thing
     * that makes a test class independent of the ones before it.
     */
    private function dropEverything(): void
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();

        [$list, $before, $after] = match (Database::driver()) {
            'pgsql' => [
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
                null,
                null,
            ],
            'sqlite' => [
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
                'PRAGMA foreign_keys = OFF',
                'PRAGMA foreign_keys = ON',
            ],
            default => ['SHOW TABLES', 'SET FOREIGN_KEY_CHECKS = 0', 'SET FOREIGN_KEY_CHECKS = 1'],
        };

        $tables = $connection->fetchFirstColumn($list);

        if ($tables === []) {
            return;
        }

        if ($before !== null) {
            $connection->executeStatement($before);
        }

        foreach ($tables as $table) {
            if (! is_string($table)) {
                continue;
            }

            // PostgreSQL has no FK switch, so the dependants go with it.
            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s%s',
                $platform->quoteIdentifier($table),
                Database::driver() === 'pgsql' ? ' CASCADE' : '',
            ));
        }

        if ($after !== null) {
            $connection->executeStatement($after);
        }
    }

    /**
     * `dropEverything()` leaves tables that live in a schema of their own —
     * observed on MySQL, where the second run of a fixture mapped to
     * `archive.entries` failed on "table already exists". Nothing in the
     * package drops schemas, so this is the harness catching up rather
     * than a fix.
     */
    private function dropQualifiedTables(): void
    {
        foreach (MappedTables::of($this->em) as $table) {
            if (! str_contains($table, '.')) {
                continue;
            }

            [$schema, $name] = explode('.', $table, 2);

            $this->em->getConnection()->executeStatement(
                sprintf('DROP TABLE IF EXISTS %s.%s', $schema, $name),
            );
        }
    }

    /**
     * A second mapping over the same connection.
     *
     * Lets a test set up one shape of the schema and then ask what
     * another mapping would change about it — without hand-writing the
     * DDL that differs per driver, which is the thing under test.
     */
    public function mappingFor(string $fixtureDir): EntityManagerInterface
    {
        return $this->entityManager($fixtureDir);
    }

    private function loadProjections(): void
    {
        $dir = sys_get_temp_dir().'/differential-'.getmypid().'-'.str_replace('\\', '_', $this->namespace);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ((new ProjectionGenerator($this->em, $this->namespace))->generate() as $projection) {
            $file = $dir.'/'.$projection->className.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;

            $class = $this->namespace.'\\'.$projection->className;

            if (! is_subclass_of($class, Model::class)) {
                throw new RuntimeException($class.' was not generated as an Eloquent model');
            }

            $this->projections[$projection->className] = $class;
        }
    }

    public function em(): EntityManagerInterface
    {
        return $this->em;
    }

    /** @return class-string<Model> */
    public function projection(string $shortName): string
    {
        return $this->projections[$shortName]
            ?? throw new RuntimeException('No projection generated for '.$shortName);
    }

    /** Drops everything Doctrine holds, so the next read goes to the database. */
    public function forget(): void
    {
        $this->em->clear();
    }
}
