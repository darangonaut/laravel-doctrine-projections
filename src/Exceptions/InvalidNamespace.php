<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Exceptions;

use RuntimeException;

/**
 * The configured projection namespace cannot appear in a `namespace`
 * statement.
 *
 * A leading or trailing backslash is the common case, and the worst
 * shape of failure: the command wrote every file and reported success,
 * and the parse error surfaced later in generated code rather than at
 * the config line responsible.
 */
final class InvalidNamespace extends RuntimeException
{
    public static function because(string $namespace, string $reason): self
    {
        return new self(sprintf(
            'The projection namespace "%s" cannot be used: %s. Set `namespace` in '
            .'config/doctrine-projections.php to a plain namespace, without leading or trailing '
            .'backslashes — for example App\\Models\\Projections.',
            $namespace,
            $reason,
        ));
    }
}
