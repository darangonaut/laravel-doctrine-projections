<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Generation;

/**
 * One rendered projection. It carries its warnings so the generator never
 * has to know about a console — the caller prints them.
 */
final readonly class RenderedProjection
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $className,
        public string $entityClass,
        public string $tableName,
        public string $code,
        public array $warnings = [],
    ) {}
}
