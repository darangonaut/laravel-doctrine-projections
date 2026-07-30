<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Model events only guard instance-level writes (save, update, delete on a
 * model). Bulk operations on the builder — `Model::query()->update()`,
 * `insert()`, `upsert()` — fire no events at all and would go straight to
 * the table.
 *
 * `touch()` deserves special mention: it does not call `update()` on this
 * builder but `$this->toBase()->update()`, so overriding `update()` alone
 * would miss it. That is the kind of gap ReadOnlyBuilderCoverageTest exists
 * to catch when Laravel changes.
 *
 * Deliberate boundary: `DB::table('books')->update()` cannot be blocked from
 * here. That is bypassing the ORM entirely, the same as raw SQL in Doctrine.
 * The promise is "you cannot write through the model", not "you cannot write
 * to the table".
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ReadOnlyBuilder extends Builder
{
    /** @param  array<int|string, mixed>  $values */
    public function insert(array $values): never
    {
        $this->refuse('insert');
    }

    /** @param  array<string, mixed>  $values */
    public function insertGetId(array $values, ?string $sequence = null): never
    {
        $this->refuse('insertGetId');
    }

    /** @param  array<int|string, mixed>  $values */
    public function insertOrIgnore(array $values): never
    {
        $this->refuse('insertOrIgnore');
    }

    /** @param  array<int, string>  $columns */
    public function insertUsing(array $columns, mixed $query): never
    {
        $this->refuse('insertUsing');
    }

    /** @param  array<int, string>  $columns */
    public function insertOrIgnoreUsing(array $columns, mixed $query): never
    {
        $this->refuse('insertOrIgnoreUsing');
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): never
    {
        $this->refuse('update');
    }

    /** @param  array<string, mixed>  $values */
    public function updateFrom(array $values): never
    {
        $this->refuse('updateFrom');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function updateOrInsert(array $attributes, array $values = []): never
    {
        $this->refuse('updateOrInsert');
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->refuse('upsert');
    }

    /** @param  array<string, mixed>  $extra */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('increment');
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): never
    {
        $this->refuse('incrementEach');
    }

    /** @param  array<string, mixed>  $extra */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('decrement');
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): never
    {
        $this->refuse('decrementEach');
    }

    /**
     * Transitively this would be caught by firstOrCreate's model events,
     * but relying on someone else's implementation is brittle and the
     * error would point at an unrelated path.
     */
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $extra
     */
    public function incrementOrCreate(
        array $attributes,
        string $column = 'count',
        $default = 1,
        $step = 1,
        array $extra = [],
    ): never {
        $this->refuse('incrementOrCreate');
    }

    /**
     * Writes via toBase(), so the update() override above does not see it.
     *
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null): never
    {
        $this->refuse('touch');
    }

    public function delete(): never
    {
        $this->refuse('delete');
    }

    public function forceDelete(): never
    {
        $this->refuse('forceDelete');
    }

    public function truncate(): never
    {
        $this->refuse('truncate');
    }

    /**
     * A composite-key projection has no `$primaryKey`, so `find()` builds
     * `where seats. = 1` and the caller gets `no such column: seats.`
     * plus a PHP deprecation from inside Eloquent. Refusing up front says
     * what is actually wrong and what to do instead.
     *
     * @param  mixed  $id
     * @param  array<int, string>  $columns
     */
    public function find($id, $columns = ['*']): mixed
    {
        $this->guardAgainstCompositeKey('find');

        return parent::find($id, $columns);
    }

    /**
     * @param  Arrayable<array-key, mixed>|array<int, mixed>  $ids
     * @param  array<int, string>  $columns
     */
    public function findMany($ids, $columns = ['*']): Collection
    {
        $this->guardAgainstCompositeKey('findMany');

        return parent::findMany($ids, $columns);
    }

    /**
     * The comparison is against an empty string rather than null on
     * purpose: Laravel declares `getKeyName(): string`, so static analysis
     * rules out the null a composite-key projection actually has. Casting
     * covers both, and a blank key name is just as unusable as a missing
     * one.
     */
    private function guardAgainstCompositeKey(string $operation): void
    {
        if ((string) $this->getModel()->getKeyName() !== '') {
            return;
        }

        throw UnsupportedMapping::compositeKeyLookup($operation, $this->getModel()::class);
    }

    private function refuse(string $operation): never
    {
        throw ReadOnlyProjection::attemptedTo($operation.'() on the builder', $this->getModel()::class);
    }
}
