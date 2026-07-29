<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Exceptions;

use RuntimeException;

final class DuplicateProjectionName extends RuntimeException
{
    /** @param array<string, list<string>> $clashes short name => colliding FQCNs */
    public static function between(array $clashes): self
    {
        $lines = [];

        foreach ($clashes as $base => $classes) {
            $lines[] = sprintf('  %s: %s', $base, implode(', ', $classes));
        }

        return new self(
            "Two entities share a short name, their projections would overwrite each other:\n"
            .implode("\n", $lines),
        );
    }
}
