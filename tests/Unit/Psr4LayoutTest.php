<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every PHP file's declared namespace has to match its directory exactly,
 * including case.
 *
 * This is invisible on macOS and Windows, where the filesystem does not
 * care, and fatal on Linux, where the autoloader finds nothing. It is the
 * same hazard the generator refuses entities over — and it got in here
 * anyway, as a fixture directory called `xml` under a namespace called
 * `Xml`: green on the machine it was written on, four failed CI jobs on
 * the next push.
 *
 * Checked over `src/` too, though a package that could not autoload
 * itself would fail more loudly.
 */
final class Psr4LayoutTest extends TestCase
{
    /** @return list<array{string, string}> */
    private static function roots(): array
    {
        $base = dirname(__DIR__, 2);

        return [
            [$base.'/src', 'Darangonaut\\DoctrineProjections'],
            [$base.'/tests', 'Darangonaut\\DoctrineProjections\\Tests'],
        ];
    }

    #[Test]
    public function every_file_sits_where_its_namespace_says(): void
    {
        $wrong = [];
        $checked = 0;

        foreach (self::roots() as [$root, $prefix]) {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
                    // scripts run standalone, not autoloaded
                    continue;
                }

                $checked++;

                $namespace = trim($matches[1]);
                $relative = str_replace('\\', '/', (string) substr($namespace, strlen($prefix)));
                $expected = $root.$relative.'/'.$file->getBasename();

                if ($expected !== $file->getPathname()) {
                    $wrong[] = $file->getPathname().' declares '.$namespace;
                }
            }
        }

        self::assertGreaterThan(50, $checked, 'the scan found almost nothing — is the path right?');
        self::assertSame([], $wrong);
    }
}
