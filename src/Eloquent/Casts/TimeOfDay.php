<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A TIME column, read the way Doctrine reads one.
 *
 * Laravel has no time cast, so `14:30:00` used to go through the datetime
 * cast and come back as *today* at 14:30 while the entity said
 * 1970-01-01 14:30 — the same clock time on two different days, which
 * compares unequal and formats differently.
 *
 * Doctrine anchors a time at the epoch (`createFromFormat('!H:i:s')`);
 * this does the same, so the two sides describe the same value.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|string|null>
 */
final class TimeOfDay implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        // The leading ! zeroes every field the format does not set, which
        // is what puts the date at 1970-01-01 rather than today.
        $time = CarbonImmutable::createFromFormat('!H:i:s', (string) $value);

        return $time instanceof CarbonImmutable ? $time : null;
    }

    /**
     * Back to what the column holds.
     *
     * This used to throw, on the reasoning that a read-only model has no
     * business being written to. It is not only user writes that come
     * through here: Eloquent flushes its cached cast objects back into
     * the attribute array on `getAttributes()`, which `toJson()`,
     * `refresh()`, `getDirty()` and serializing a model for a queue or a
     * cache all reach. So `toJson()` on any projection with a time column
     * threw ReadOnlyProjection — a read, refused.
     *
     * Nothing is weakened by converting instead. The lock is on
     * persistence — save(), delete(), the builder, the model events — and
     * every other column on a projection already accepts an in-memory
     * assignment that goes nowhere.
     *
     * The format is Doctrine's own for a time column.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        if ($value instanceof DateTimeInterface) {
            return [$key => $value->format('H:i:s')];
        }

        return [$key => $value];
    }
}
