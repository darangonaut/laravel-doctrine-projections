<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a generated projection refuse every write.
 *
 * Three layers are needed, because each covers what the others miss:
 *
 *   model events        instance writes: save(), update(), delete(), create()
 *   ReadOnlyBuilder     bulk writes: query()->update(), insert(), upsert(), touch()
 *   ReadOnlyBelongsToMany   pivot writes: attach(), detach(), sync()
 *
 * Hydration from the database goes through setRawAttributes(), so reading
 * is untouched.
 *
 * The trait is not called `ReadOnly`: `use ReadOnly;` does not parse on
 * PHP 8.4, where `readonly` is a keyword.
 */
trait ReadOnlyModel
{
    protected static function bootReadOnlyModel(): void
    {
        foreach (['creating', 'updating', 'saving', 'deleting'] as $event) {
            static::$event(static function (self $model) use ($event): never {
                throw ReadOnlyProjection::attemptedTo($event, $model::class);
            });
        }
    }

    /**
     * Laravel annotates its own newEloquentBuilder() with the wildcard
     * generic too — the constructor takes a query builder and cannot
     * infer the model type from it.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return ReadOnlyBuilder<*>
     */
    public function newEloquentBuilder($query): ReadOnlyBuilder
    {
        return new ReadOnlyBuilder($query);
    }

    /**
     * Mirrors Laravel's own signature so the generics line up — the only
     * change is which class comes back.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  string|class-string<Model>  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string|null  $relationName
     * @return ReadOnlyBelongsToMany<TRelatedModel, TDeclaringModel>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ): ReadOnlyBelongsToMany {
        return new ReadOnlyBelongsToMany(
            $query, $parent, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey, $relatedKey, $relationName,
        );
    }
}
