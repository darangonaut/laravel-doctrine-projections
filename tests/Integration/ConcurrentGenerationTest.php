<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two things that only exist across processes.
 *
 * A deploy racing a CI job, or someone running the command twice, puts
 * two generates in the same directory at once. And an application
 * serving requests reads those files while a generate is running — the
 * reason the writes go through a rename rather than a delete followed by
 * a write.
 *
 * The claim being checked is narrow and worth stating plainly: a reader
 * sees either the old file or the new one, never a partial one and never
 * none. It says nothing about a *class* changing under a running worker,
 * which PHP does not allow either way.
 */
final class ConcurrentGenerationTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../Scripts/generate-to.php';

    private string $output;

    protected function setUp(): void
    {
        $this->output = sys_get_temp_dir().'/projections-concurrent-'.getmypid();

        self::removeDirectory($this->output);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->output);
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (new FilesystemIterator($dir) as $file) {
            @unlink((string) $file);
        }

        @rmdir($dir);
    }

    /** @return resource */
    private function start(int $rounds)
    {
        $command = sprintf(
            '%s %s %s %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::SCRIPT),
            escapeshellarg($this->output),
            $rounds,
        );

        $handle = popen($command.' 2>&1', 'r');

        self::assertIsResource($handle);

        return $handle;
    }

    #[Test]
    public function two_generates_at_once_leave_a_complete_set_of_files(): void
    {
        $first = $this->start(6);
        $second = $this->start(6);

        $outputs = [stream_get_contents($first), stream_get_contents($second)];

        self::assertSame(0, pclose($first));
        self::assertSame(0, pclose($second));

        foreach ($outputs as $written) {
            self::assertSame('4', trim((string) $written));
        }

        // No temp file survived, and every projection parses.
        foreach (new FilesystemIterator($this->output) as $file) {
            self::assertStringEndsWith('.php', (string) $file);
            self::assertStringNotContainsString('.tmp', (string) $file);
        }

        self::assertFileExists($this->output.'/Account.php');
        self::assertStringContainsString('class Account', (string) file_get_contents($this->output.'/Account.php'));
    }

    /**
     * A reader running flat out against a directory being rewritten. Each
     * read must come back as a whole file — the failure this rules out is
     * an empty or half-written `Account.php` handed to an autoloader
     * mid-request.
     */
    #[Test]
    public function a_reader_never_sees_a_partial_or_missing_file(): void
    {
        // one complete round first, so there is something to read
        $warmup = $this->start(1);
        $warmupOutput = trim((string) stream_get_contents($warmup));

        self::assertSame(0, pclose($warmup), 'warm-up run failed: '.$warmupOutput);
        self::assertSame('4', $warmupOutput);

        $expected = (string) file_get_contents($this->output.'/Account.php');
        $writer = $this->start(40);

        $reads = 0;
        $bad = [];

        while (! feof($writer)) {
            for ($i = 0; $i < 50; $i++) {
                $seen = @file_get_contents($this->output.'/Account.php');
                $reads++;

                if ($seen !== $expected) {
                    $bad[] = $seen === false ? '(missing)' : substr((string) $seen, 0, 40);
                }
            }

            // one non-blocking-ish poll of the writer's output
            stream_set_blocking($writer, false);
            stream_get_contents($writer);
            stream_set_blocking($writer, true);

            if ($reads > 5000) {
                break;
            }
        }

        pclose($writer);

        self::assertGreaterThan(100, $reads, 'the reader barely ran — the check would prove nothing');
        self::assertSame([], array_slice($bad, 0, 3), sprintf('%d of %d reads were not a whole file', count($bad), $reads));
    }
}
