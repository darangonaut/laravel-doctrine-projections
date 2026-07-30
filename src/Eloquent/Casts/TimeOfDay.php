<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent\Casts;

use Carbon\CarbonImmutable;
use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
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
 * @implements CastsAttributes<CarbonImmutable, never>
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

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): never
    {
        throw ReadOnlyProjection::attemptedTo('set '.$key, $model::class);
    }
}
