<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot writes bypass model events — `attach()` and friends go straight
 * through the query builder, so `saving`/`deleting` never fire.
 *
 * Without this class the lock is porous: a read-only projection could
 * still rewrite many-to-many links.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
 */
class ReadOnlyBelongsToMany extends BelongsToMany
{
    public function attach($id, array $attributes = [], $touch = true): void
    {
        $this->refuse('attach');
    }

    public function detach($ids = null, $touch = true): never
    {
        $this->refuse('detach');
    }

    public function sync($ids, $detaching = true): never
    {
        $this->refuse('sync');
    }

    public function syncWithoutDetaching($ids): never
    {
        $this->refuse('syncWithoutDetaching');
    }

    public function syncWithPivotValues($ids, array $values, bool $detaching = true): never
    {
        $this->refuse('syncWithPivotValues');
    }

    public function toggle($ids, $touch = true): never
    {
        $this->refuse('toggle');
    }

    public function updateExistingPivot($id, array $attributes, $touch = true): never
    {
        $this->refuse('updateExistingPivot');
    }

    private function refuse(string $operation): never
    {
        throw ReadOnlyProjection::attemptedTo($operation.'() on the pivot', $this->getParent()::class);
    }
}
