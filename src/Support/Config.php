<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use RuntimeException;

/**
 * Typed reads out of the config repository.
 *
 * `(string) config(...)` silently turns a missing key into an empty
 * string, and an empty path means the generator would happily write into
 * the project root. Failing with the key name is more useful.
 */
final class Config
{
    public static function string(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Config value "%s" must be a non-empty string, got %s. '
                .'Did you publish the package config?',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
