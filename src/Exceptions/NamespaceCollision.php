<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Exceptions;

use RuntimeException;

/**
 * The configured projection namespace would give a generated model the
 * same fully qualified name as the entity it projects.
 *
 * Whichever the autoloader reaches first wins, so the application either
 * fatals on a redeclaration or — worse — silently gets a read-only
 * Eloquent model where it asked for the entity, and writes stop working
 * for reasons nothing explains.
 */
final class NamespaceCollision extends RuntimeException
{
    /** @param list<string> $classes */
    public static function with(string $namespace, array $classes): self
    {
        return new self(sprintf(
            'The projection namespace "%s" is also where %s live%s, so the generated model would '
            .'have the same class name as the entity. Point `namespace` in '
            .'config/doctrine-projections.php somewhere of its own.',
            $namespace,
            implode(', ', $classes),
            count($classes) === 1 ? 's' : '',
        ));
    }
}
