<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

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
    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     */
    public function attach($id, array $attributes = [], $touch = true): void
    {
        $this->refuse('attach');
    }

    /** @param  mixed  $ids */
    public function detach($ids = null, $touch = true): never
    {
        $this->refuse('detach');
    }

    /** @param  Collection<array-key, mixed>|Model|array<array-key, mixed>  $ids */
    public function sync($ids, $detaching = true): never
    {
        $this->refuse('sync');
    }

    /** @param  Collection<array-key, mixed>|Model|array<array-key, mixed>  $ids */
    public function syncWithoutDetaching($ids): never
    {
        $this->refuse('syncWithoutDetaching');
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>  $ids
     * @param  array<string, mixed>  $values
     */
    public function syncWithPivotValues($ids, array $values, bool $detaching = true): never
    {
        $this->refuse('syncWithPivotValues');
    }

    /** @param  mixed  $ids */
    public function toggle($ids, $touch = true): never
    {
        $this->refuse('toggle');
    }

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): never
    {
        $this->refuse('updateExistingPivot');
    }

    private function refuse(string $operation): never
    {
        throw ReadOnlyProjection::attemptedTo($operation.'() on the pivot', $this->getParent()::class);
    }
}
