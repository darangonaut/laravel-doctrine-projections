<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Exceptions;

use LogicException;

final class ReadOnlyProjection extends LogicException
{
    public static function attemptedTo(string $operation, string $model): self
    {
        return new self(sprintf(
            '%s is a read-only projection (attempted %s). Write through the Doctrine entity instead.',
            class_basename($model),
            $operation,
        ));
    }
}
