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
     * Eloquent addresses a row by one column. With a composite key there
     * is no such column, and the generated projection says so with
     * `$primaryKey = null` — at which point `find()` composes
     * `where seats. = 1` and fails with `no such column: seats.`, which
     * tells the caller nothing.
     */
    public static function compositeKeyLookup(string $operation, string $model): self
    {
        return new self(sprintf(
            '%s::%s() cannot work: %s projects an entity with a composite primary key, '
            .'which Eloquent cannot address. Query it by its key columns instead, '
            .'for example ->where([...])->first().',
            $model,
            $operation,
            $model,
        ));
    }

    /**
     * `#[ORM\OrderBy]` names fields on the target entity, which have to be
     * resolved to columns before Eloquent can sort by them. If one cannot
     * be resolved, emitting the relation without its ordering would leave
     * the projection quietly disagreeing with the entity about row order —
     * exactly the drift this package is supposed to remove.
     */
    public static function unorderableAssociation(string $entity, string $field): self
    {
        return new self(sprintf(
            'The association on %s orders by "%s", which is not a field on the target entity, '
            .'so it cannot be translated to a column. Order by a mapped field, or drop the '
            .'#[ORM\OrderBy] and sort at the call site.',
            $entity,
            $field,
        ));
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
