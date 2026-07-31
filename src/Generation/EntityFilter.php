<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Generation;

/**
 * Decides which mapped entities get a projection.
 *
 * Useful when the mapping is large but only part of it is ever read
 * through Eloquent, and when some entities cannot be projected at all
 * (class table inheritance, say) but the rest should still generate.
 *
 * Patterns are matched with `fnmatch()` against the fully qualified
 * class name, so `App\Entity\Billing\*` works as expected.
 */
final class EntityFilter
{
    /**
     * @param  list<string>  $only  empty means every mapped entity
     * @param  list<string>  $except  applied after `only`
     */
    public function __construct(
        private readonly array $only = [],
        private readonly array $except = [],
    ) {}

    public static function everything(): self
    {
        return new self;
    }

    /**
     * Whether any pattern is configured at all.
     *
     * "Nothing was generated" has two very different causes — a mapping
     * with no entities, and a pattern that matched none of them — and one
     * message for both used to send people to check a mapping that was
     * fine.
     */
    public function isNarrowing(): bool
    {
        return $this->only !== [] || $this->except !== [];
    }

    public function accepts(string $entityClass): bool
    {
        if ($this->only !== [] && ! $this->matchesAny($entityClass, $this->only)) {
            return false;
        }

        return ! $this->matchesAny($entityClass, $this->except);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $entityClass, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($entityClass === $pattern || fnmatch($pattern, $entityClass, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
