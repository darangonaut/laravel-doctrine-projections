<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Exceptions;

use RuntimeException;

/**
 * Raised where a mapping cannot be represented as a plain Eloquent model.
 *
 * Refusing is deliberate: a projection that silently returns the wrong
 * rows is worse than no projection at all.
 */
final class UnsupportedMapping extends RuntimeException
{
    /** @param list<string> $classes */
    public static function joinedInheritance(array $classes): self
    {
        return new self(
            "Class table inheritance (JOINED) cannot be projected — the entity spans several\n"
            ."tables and an Eloquent model is bound to one. Affected:\n  "
            .implode("\n  ", $classes)
            ."\n\nUse SINGLE_TABLE inheritance, or exclude these entities from generation."
        );
    }
}
