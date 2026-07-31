<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Shared hosting and hardened deploys pin `open_basedir` to the project,
 * and a read-only or absent `/tmp` is ordinary in a container.
 *
 * Generation reads mapping metadata and returns strings, so it should
 * need nothing outside the project — but "should" is the reason to run
 * it that way rather than reason about it. The proxy directory Doctrine
 * is configured with points at the system temp dir here, which is
 * exactly the sort of thing that turns out to be touched after all.
 */
final class OpenBasedirTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../Scripts/generate.php';

    /** @return array{int, string} */
    private function generateInSubprocess(string ...$flags): array
    {
        $binary = PHP_BINARY;

        $command = escapeshellarg($binary).' -n -d date.timezone=UTC';

        foreach ($flags as $flag) {
            $command .= ' '.$flag;
        }

        $command .= ' '.escapeshellarg(self::SCRIPT).' 2>&1';

        $output = [];
        $status = 0;

        exec($command, $output, $status);

        return [$status, implode("\n", $output)];
    }

    #[Test]
    public function generation_needs_nothing_outside_the_project(): void
    {
        [$status, $output] = $this->generateInSubprocess();

        if ($status !== 0) {
            // `php -n` drops the ini files, so an extension configured
            // there is gone. That is about this machine, not the package.
            self::markTestSkipped('generation does not run under `php -n` here: '.$output);
        }

        $project = dirname(__DIR__, 2);

        [$restricted, $restrictedOutput] = $this->generateInSubprocess(
            '-d '.escapeshellarg('open_basedir='.$project),
        );

        self::assertSame(0, $restricted, 'generation reached outside the project: '.$restrictedOutput);
        self::assertSame(trim($output), trim($restrictedOutput), 'and produced the same result');
    }
}
