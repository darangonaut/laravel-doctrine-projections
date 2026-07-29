<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Illuminate\Database\Eloquent\Builder;

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
    public function insert(array $values): never
    {
        $this->refuse('insert');
    }

    public function insertGetId(array $values, $sequence = null): never
    {
        $this->refuse('insertGetId');
    }

    public function insertOrIgnore(array $values): never
    {
        $this->refuse('insertOrIgnore');
    }

    public function insertUsing(array $columns, $query): never
    {
        $this->refuse('insertUsing');
    }

    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        $this->refuse('insertOrIgnoreUsing');
    }

    public function update(array $values): never
    {
        $this->refuse('update');
    }

    public function updateFrom(array $values): never
    {
        $this->refuse('updateFrom');
    }

    public function updateOrInsert(array $attributes, $values = []): never
    {
        $this->refuse('updateOrInsert');
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->refuse('upsert');
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('increment');
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        $this->refuse('incrementEach');
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('decrement');
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        $this->refuse('decrementEach');
    }

    /**
     * Transitively this would be caught by firstOrCreate's model events,
     * but relying on someone else's implementation is brittle and the
     * error would point at an unrelated path.
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

    /** Writes via toBase(), so the update() override above does not see it. */
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

    private function refuse(string $operation): never
    {
        throw ReadOnlyProjection::attemptedTo($operation.'() on the builder', $this->getModel()::class);
    }
}
