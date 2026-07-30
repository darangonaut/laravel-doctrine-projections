<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent\Casts;

use Darangonaut\DoctrineProjections\Exceptions\ReadOnlyProjection;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Doctrine's `simple_array`: a comma-separated list in one column.
 *
 * Laravel's `array` cast is JSON, so there was nothing to use and the
 * column came back as the raw string `dom,kúrenie` while the entity had
 * `['dom', 'kúrenie']`.
 *
 * Matches `SimpleArrayType::convertToPHPValue()`, null included — Doctrine
 * returns an empty array there rather than null.
 *
 * @implements CastsAttributes<list<string>, never>
 */
final class SimpleArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value,
            ));
        }

        return is_scalar($value) ? explode(',', (string) $value) : [];
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): never
    {
        throw ReadOnlyProjection::attemptedTo('set '.$key, $model::class);
    }
}
