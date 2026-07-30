<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Schema;

final readonly class ClassifiedStatements
{
    /**
     * @param  list<string>  $fatal  dropping a table we do not map — the filter is broken
     * @param  list<string>  $destructive  irreversible data loss; needs explicit consent
     * @param  list<string>  $warnings  constraint or type changes; data survives
     * @param  list<string>  $rebuiltTables  rebuilt in place with every column carried across
     */
    public function __construct(
        public array $fatal = [],
        public array $destructive = [],
        public array $warnings = [],
        public array $rebuiltTables = [],
    ) {}
}
