<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generated file lands in someone else's repository, under their
 * tooling. If it fails their PHPStan or their Pint, they are looking at
 * errors in code they did not write and cannot fix.
 *
 * The package's own analysis never covered this — it does not analyse
 * generated output — so it was invisible until it was run on purpose.
 * Two things were wrong: the `newQueryForRestoration()` override on a
 * scoped projection did not satisfy the parent's generic return type,
 * and `@property array $meta` is a bare array that level max rejects.
 */
final class GeneratedCodeQualityTest extends TestCase
{
    private const FIXTURES = ['Entities', 'Ordered', 'Casts', 'DeepInheritance', 'Identity', 'SelfRef'];

    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/generated-quality-'.getmypid();

        foreach (self::FIXTURES as $fixture) {
            $target = self::$dir.'/'.$fixture;

            if (! is_dir($target)) {
                mkdir($target, 0777, true);
            }

            foreach ((new ProjectionGenerator(
                EntityManagerFactory::forFixtures($fixture),
                'GeneratedQuality\\'.$fixture,
            ))->generate() as $projection) {
                file_put_contents($target.'/'.$projection->className.'.php', $projection->code);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir.'/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob(self::$dir.'/*') ?: [] as $dir) {
            rmdir($dir);
        }
        @rmdir(self::$dir);
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_values(array_filter(glob(self::$dir.'/*/*.php') ?: [], is_string(...)));

        self::assertNotSame([], $files, 'nothing was generated to check');

        return $files;
    }

    #[Test]
    public function every_generated_file_is_valid_php(): void
    {
        foreach ($this->files() as $file) {
            $code = file_get_contents($file);

            self::assertIsString($code);

            // the real parser, so a broken docblock or a stray brace is
            // caught rather than described
            $tokens = token_get_all($code, TOKEN_PARSE);

            self::assertNotSame([], $tokens, $file.' produced no tokens');
        }
    }

    #[Test]
    public function generated_code_passes_phpstan_at_level_max(): void
    {
        $config = sys_get_temp_dir().'/generated-quality-'.getmypid().'.neon';
        $root = dirname(__DIR__, 2);

        file_put_contents($config, implode("\n", [
            'includes:',
            '    - '.$root.'/vendor/larastan/larastan/extension.neon',
            'parameters:',
            '    level: max',
            '    paths:',
            '        - '.self::$dir,
        ]));

        $output = [];
        $status = 0;
        exec(
            escapeshellarg($root.'/vendor/bin/phpstan').' analyse -c '.escapeshellarg($config)
            .' --no-progress --error-format=raw 2>&1',
            $output,
            $status,
        );

        unlink($config);

        self::assertSame(0, $status, "generated code does not pass level max:\n".implode("\n", $output));
    }

    #[Test]
    public function generated_code_is_already_formatted_the_way_pint_wants_it(): void
    {
        $root = dirname(__DIR__, 2);

        $output = [];
        $status = 0;
        exec(
            escapeshellarg($root.'/vendor/bin/pint').' --test '.escapeshellarg(self::$dir).' 2>&1',
            $output,
            $status,
        );

        self::assertSame(0, $status, "Pint would rewrite the generated code:\n".implode("\n", $output));
    }
}
