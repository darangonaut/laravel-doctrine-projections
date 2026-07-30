<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Generation;

/**
 * Collects the `use` statements for one generated class and decides
 * whether a class can be referenced by its short name.
 *
 * It cannot when the short name is already taken — by the model being
 * generated, by another projection in the same namespace, or by a
 * different class already imported here. An entity named `HasMany` is
 * the awkward case: referencing the relation class bare would resolve to
 * that projection and the first call would throw a TypeError.
 */
final class Imports
{
    /** @var array<string, string> short name => FQCN */
    private array $map = [];

    /** @param list<string> $reserved short names of every generated projection */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $reserved,
    ) {}

    /** Short name where possible, fully qualified where it would collide. */
    public function reference(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $short = class_basename($fqcn);

        $collides = $short === $this->modelClass
            || in_array($short, $this->reserved, true)
            || (isset($this->map[$short]) && $this->map[$short] !== $fqcn);

        if ($collides) {
            return '\\'.$fqcn;
        }

        $this->map[$short] = $fqcn;

        return $short;
    }

    /** @return list<string> sorted FQCNs, ready for `use` lines */
    public function all(): array
    {
        $fqcns = array_values($this->map);
        sort($fqcns);

        return $fqcns;
    }
}
