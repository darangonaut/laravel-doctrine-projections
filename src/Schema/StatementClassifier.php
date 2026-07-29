<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Schema;

/**
 * Sorts SchemaTool output by what it can ruin.
 *
 * `DROP TABLE` is deliberately not blanket-fatal: SQLite cannot alter a
 * column except by rebuilding the table, so DBAL emits `DROP TABLE books`
 * there routinely. What matters is whether the table is one an entity
 * maps — dropping an unmapped table means the schema filter is broken.
 *
 * A single ALTER can carry several clauses, so every DROP occurrence is
 * scanned, not just the start of the statement.
 */
final class StatementClassifier
{
    /**
     * Clauses that are not followed by a column name. NOT/DEFAULT are the
     * PostgreSQL forms `ALTER col DROP NOT NULL` and `DROP DEFAULT` — both
     * lossless, but easily misread as a column drop.
     */
    private const array NOT_A_COLUMN = [
        'FOREIGN', 'INDEX', 'KEY', 'CONSTRAINT', 'PRIMARY',
        'NOT', 'DEFAULT', 'IDENTITY', 'EXPRESSION',
    ];

    /** @var list<string> */
    private readonly array $owned;

    /** @param list<string> $ownedTables tables mapped by some entity */
    public function __construct(array $ownedTables)
    {
        $this->owned = array_map('strtoupper', $ownedTables);
    }

    /** @param list<string> $sql */
    public function classify(array $sql): ClassifiedStatements
    {
        $fatal = $destructive = $warnings = [];

        foreach ($sql as $statement) {
            $upper = strtoupper($statement);

            if (preg_match('/^\s*TRUNCATE\b/', $upper)) {
                $destructive[] = $statement;

                continue;
            }

            if (preg_match('/^\s*DROP\s+DATABASE\b/', $upper)) {
                $fatal[] = $statement;

                continue;
            }

            if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(\S+)/', $upper, $m)) {
                $table = trim($m[1], '`"[]\';');

                // Rebuild scratch tables belong to the same step.
                if (in_array($table, $this->owned, true) || str_contains($table, '__TEMP__')) {
                    $destructive[] = $statement;
                } else {
                    $fatal[] = $statement;
                }

                continue;
            }

            $dropsColumn = false;
            $dropsConstraint = str_starts_with($upper, 'DROP INDEX');

            if (preg_match_all('/\bDROP\s+(COLUMN\s+)?(\S+)/', $upper, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    if ($m[1] !== '' || ! in_array(rtrim($m[2], ','), self::NOT_A_COLUMN, true)) {
                        $dropsColumn = true;
                    } else {
                        $dropsConstraint = true;
                    }
                }
            }

            if ($dropsColumn) {
                $destructive[] = $statement;
            } elseif ($dropsConstraint || preg_match('/\b(CHANGE|MODIFY)\b/', $upper)) {
                $warnings[] = $statement;
            }
        }

        return new ClassifiedStatements($fatal, $destructive, $warnings);
    }
}
