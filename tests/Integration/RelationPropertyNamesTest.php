<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Eloquent resolves `$doc->replacedBy` by looking for a method of exactly
 * that name. The docblock used to advertise the snake_cased
 * `$replaced_by`, which is not a property, not a column, and not a
 * relation — it read back as null without complaint while the docblock
 * and every IDE said it was a Document.
 *
 * Single-word relations hid this for a long time: `parent` is the same in
 * both cases. So the check is against a loaded model and a real row, not
 * against the emitted string.
 */
final class RelationPropertyNamesTest extends TestCase
{
    private const NAMESPACE = 'RelationNameFixtures';

    /** @var class-string<Model> */
    private static string $document;

    private static string $code;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->statement(
            'CREATE TABLE documents (
                uuid VARCHAR(36) NOT NULL PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                parent_uuid VARCHAR(36) NULL,
                replaced_by_uuid VARCHAR(36) NULL
            )',
        );

        $capsule->getConnection()->table('documents')->insert([
            ['uuid' => 'aaa', 'title' => 'Nová zmluva', 'parent_uuid' => null, 'replaced_by_uuid' => null],
            ['uuid' => 'bbb', 'title' => 'Stará zmluva', 'parent_uuid' => null, 'replaced_by_uuid' => 'aaa'],
        ]);

        $dir = sys_get_temp_dir().'/relation-names-'.getmypid();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ((new ProjectionGenerator(
            EntityManagerFactory::forFixtures('Entities'),
            self::NAMESPACE,
        ))->generate() as $projection) {
            $file = $dir.'/'.$projection->className.'.php';
            file_put_contents($file, $projection->code);
            require_once $file;

            if ($projection->className === 'Document') {
                self::$code = $projection->code;
            }
        }

        self::$document = self::projection('Document');
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

    #[Test]
    public function every_documented_relation_property_actually_resolves(): void
    {
        $model = new (self::$document);

        preg_match_all('/@property [^$]+\$(\w+)/', self::$code, $matches);

        $documented = $matches[1];
        self::assertContains('replacedBy', $documented, 'the fixture is meant to have a two-word relation');

        foreach ($documented as $property) {
            // a documented name is either a column on the table or a
            // relation method — anything else reads back as null
            $isColumn = in_array($property, ['uuid', 'title', 'parent_uuid', 'replaced_by_uuid'], true);

            self::assertTrue(
                $isColumn || method_exists($model, $property),
                "@property \${$property} is neither a column nor a relation method",
            );
        }
    }

    #[Test]
    public function the_two_word_relation_loads_a_real_row(): void
    {
        $document = self::$document::query()->find('bbb');

        self::assertNotNull($document);

        // getAttribute() is the same resolution path `->replacedBy` takes;
        // written this way the property is not undefined to static analysis
        $replacedBy = $document->getAttribute('replacedBy');

        self::assertInstanceOf(Model::class, $replacedBy, '$document->replacedBy must resolve');
        self::assertSame('Nová zmluva', $replacedBy->getAttribute('title'));
    }

    #[Test]
    public function eager_loading_accepts_the_documented_name(): void
    {
        $document = self::$document::query()->with('replacedBy')->find('bbb');

        self::assertNotNull($document);
        self::assertTrue($document->relationLoaded('replacedBy'));
    }

    #[Test]
    public function the_snake_cased_spelling_is_not_documented(): void
    {
        // it would resolve to null forever, which is worse than absent
        self::assertStringNotContainsString('$replaced_by ', self::$code);
    }
}
