<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `#[ORM\OrderBy]` used to be dropped on the floor, so the same
 * association came back in one order through the entity and another
 * through the projection — measured on a list whose insertion order was
 * the reverse of its `position`. Nothing failed; the rows were simply in
 * the wrong sequence, which is the kind of drift this package exists to
 * remove.
 *
 * The rows are inserted deliberately out of order, so a relation with no
 * ordering at all would come back in exactly the wrong sequence.
 */
final class OrderedRelationTest extends TestCase
{
    private const NAMESPACE = 'OrderedFixtures';

    /** @var class-string<Model> */
    private static string $album;

    private static string $code;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $connection = $capsule->getConnection();

        $connection->statement('CREATE TABLE albums (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(120) NOT NULL)');
        $connection->statement(
            'CREATE TABLE tracks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(120) NOT NULL,
                position INTEGER NOT NULL,
                disc_number INTEGER NOT NULL,
                album_id INTEGER NOT NULL
            )',
        );

        $connection->table('albums')->insert(['id' => 1, 'title' => 'Dvojalbum']);

        // insertion order is neither the expected order nor its reverse,
        // so passing by luck is not on the table
        $connection->table('tracks')->insert([
            ['id' => 1, 'title' => 'disk 1, prvá', 'position' => 1, 'disc_number' => 1, 'album_id' => 1],
            ['id' => 2, 'title' => 'disk 2, druhá', 'position' => 2, 'disc_number' => 2, 'album_id' => 1],
            ['id' => 3, 'title' => 'disk 1, druhá', 'position' => 2, 'disc_number' => 1, 'album_id' => 1],
            ['id' => 4, 'title' => 'disk 2, prvá', 'position' => 1, 'disc_number' => 2, 'album_id' => 1],
        ]);

        $dir = sys_get_temp_dir().'/ordered-projections-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ((new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Ordered'),
            self::NAMESPACE,
        ))->generate() as $projection) {
            $file = $dir.'/'.$projection->className.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;

            if ($projection->className === 'Album') {
                self::$code = $projection->code;
            }
        }

        self::$album = self::projection('Album');
    }

    /** @return class-string<Model> */
    private static function projection(string $name): string
    {
        $class = self::NAMESPACE.'\\'.$name;

        self::assertTrue(class_exists($class), $class.' was not generated');
        self::assertTrue(is_subclass_of($class, Model::class));

        /** @var class-string<Model> */
        return $class;
    }

    /**
     * @param  Collection<int, Model>  $tracks
     * @return list<string>
     */
    private static function titles(Collection $tracks): array
    {
        $titles = [];

        foreach ($tracks as $track) {
            $title = $track->getAttribute('title');

            self::assertIsString($title);

            $titles[] = $title;
        }

        return $titles;
    }

    /** @return list<string> */
    private function trackTitles(): array
    {
        $album = self::$album::query()->find(1);

        self::assertNotNull($album);

        /** @var Collection<int, Model> $tracks */
        $tracks = $album->getAttribute('tracks');

        return self::titles($tracks);
    }

    #[Test]
    public function both_ordering_columns_reach_the_relation_in_order(): void
    {
        self::assertStringContainsString(
            "->orderBy('disc_number', 'desc')\n            ->orderBy('position', 'asc')",
            self::$code,
        );
    }

    /**
     * The field is `discNumber`; the column is `disc_number`. Emitting the
     * field name would produce SQL for a column that does not exist, and
     * every read through the relation would throw.
     */
    #[Test]
    public function the_field_name_is_translated_to_its_column(): void
    {
        self::assertStringNotContainsString('discNumber', self::$code);
    }

    #[Test]
    public function the_rows_come_back_in_the_order_the_mapping_asks_for(): void
    {
        self::assertSame(
            ['disk 2, prvá', 'disk 2, druhá', 'disk 1, prvá', 'disk 1, druhá'],
            $this->trackTitles(),
            'disc_number descending first, then position ascending',
        );
    }

    #[Test]
    public function eager_loading_orders_the_same_way(): void
    {
        $album = self::$album::query()->with('tracks')->first();

        self::assertNotNull($album);

        /** @var Collection<int, Model> $tracks */
        $tracks = $album->getAttribute('tracks');

        self::assertSame(
            ['disk 2, prvá', 'disk 2, druhá', 'disk 1, prvá', 'disk 1, druhá'],
            self::titles($tracks),
            'a separate query path — it has to sort too',
        );
    }

    #[Test]
    public function an_unordered_relation_gains_no_order_by(): void
    {
        $track = self::projection('Track');

        self::assertStringNotContainsString('orderBy', (string) file_get_contents(
            (new \ReflectionClass($track))->getFileName() ?: '',
        ));
    }
}
