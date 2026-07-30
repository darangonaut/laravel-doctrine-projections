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

    /**
     * Doctrine's mapping classes split by side, and the resolution above
     * assumes the counterpart of an inverse side is an owning side of the
     * matching kind. If that ever fails, guessing would be worse than
     * saying so.
     */
    public static function unexpectedOwningSide(string $target, string $actual): self
    {
        return new self(sprintf(
            'Could not resolve the owning side of the association on %s — got %s. '
            .'Please report this mapping.',
            $target,
            $actual,
        ));
    }
}
