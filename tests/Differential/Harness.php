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
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
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
        (new SchemaTool($this->em))->dropSchema($metadata);
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
