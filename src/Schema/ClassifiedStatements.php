<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Schema;

final readonly class ClassifiedStatements
{
    /**
     * @param  list<string>  $fatal  dropping a table we do not map — the filter is broken
     * @param  list<string>  $destructive  irreversible data loss; needs explicit consent
     * @param  list<string>  $warnings  constraint or type changes; data survives
     */
    public function __construct(
        public array $fatal = [],
        public array $destructive = [],
        public array $warnings = [],
    ) {}
}
