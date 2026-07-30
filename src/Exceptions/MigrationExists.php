<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Exceptions;

use RuntimeException;

/**
 * The generated file name is the timestamp plus `--name`, to the second.
 * Two runs inside one second with the same name resolved to the same
 * path, and the second silently replaced the first — losing a migration
 * nobody knew had been written.
 *
 * The default name is `doctrine_diff`, so it took no unusual usage: two
 * plain `doctrine:diff` runs in quick succession were enough.
 */
final class MigrationExists extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self(sprintf(
            'A migration already exists at %s. The file name is the timestamp to the second plus '
            .'--name, so a second run inside the same second would replace it. Pass a different '
            .'--name, or run again in a moment.',
            $path,
        ));
    }
}
