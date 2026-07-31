<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Eloquent\Casts;

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
 * @implements CastsAttributes<list<string>, iterable<mixed>|string|null>
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

    /**
     * Back to the comma-separated string the column holds.
     *
     * Throwing here refused reads, not writes: Eloquent flushes cached
     * cast objects back into the attribute array inside
     * `getAttributes()`, which `toJson()`, `refresh()` and serializing a
     * model all go through. See TimeOfDay::set() for the whole story.
     *
     * Doctrine writes null rather than an empty string for an empty list,
     * and this matches it so a round trip through the cast lands on the
     * same value the entity would have stored.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (is_iterable($value)) {
            $items = [];

            foreach ($value as $item) {
                $items[] = is_scalar($item) ? (string) $item : '';
            }

            return [$key => $items === [] ? null : implode(',', $items)];
        }

        return [$key => $value];
    }
}
