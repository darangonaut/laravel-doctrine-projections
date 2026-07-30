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
 * A rebuild is also not read one statement at a time. Judged alone the
 * `DROP TABLE` in the middle of one looks like total loss, when the very
 * next statement puts every row back. So rebuilds are recognised as a
 * group and checked for what they actually carry across — see
 * {@see losslessRebuilds()}.
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

    /** @var array<string, list<string>> */
    private readonly array $columnsNow;

    /**
     * @param  list<string>  $ownedTables  tables mapped by some entity
     * @param  array<string, list<string>>  $currentColumns  table => columns the database has right now
     *
     * `$currentColumns` is what makes a SQLite rebuild judgeable at all.
     * The emitted SQL alone cannot distinguish a rename from a dropped
     * column — DBAL simply omits a dropped column everywhere, so the
     * statements for both look identical. Without this the rebuild falls
     * back to being destructive, which is the safe answer.
     */
    public function __construct(array $ownedTables, array $currentColumns = [])
    {
        $this->owned = array_map('strtoupper', $ownedTables);

        $upper = [];

        foreach ($currentColumns as $table => $columns) {
            $upper[strtoupper($table)] = array_map('strtoupper', $columns);
        }

        $this->columnsNow = $upper;
    }

    /** @param list<string> $sql */
    public function classify(array $sql): ClassifiedStatements
    {
        $fatal = $destructive = $warnings = [];

        // Tables rebuilt without losing a column, plus the scratch tables
        // used to do it. Their DROPs are part of the rebuild, not loss.
        [$rebuilt, $scratch] = $this->losslessRebuilds($sql);

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
                $table = $this->identifier($m[1]);

                if (in_array($table, $rebuilt, true) || in_array($table, $scratch, true)) {
                    continue;
                }

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

        return new ClassifiedStatements($fatal, $destructive, $warnings, $rebuilt);
    }

    /**
     * Finds SQLite table rebuilds that carry every existing column across.
     *
     * The idiom DBAL emits is five statements:
     *
     *     CREATE TEMPORARY TABLE __temp__t AS SELECT a, b FROM t;
     *     DROP TABLE t;
     *     CREATE TABLE t (...);
     *     INSERT INTO t (a, c) SELECT a, b FROM __temp__t;
     *     DROP TABLE __temp__t;
     *
     * Note what the judgement is NOT made on. Comparing the parked list
     * against the restored one is tautological: DBAL parks precisely the
     * columns it intends to carry, so the two always match — a dropped
     * column simply never appears anywhere in the SQL, making a drop and
     * a rename textually identical. Measured on real SchemaTool output,
     * not assumed.
     *
     * So the comparison is against the columns the table actually has
     * right now. Every one of them parked means every one comes back;
     * one missing is a column about to be dropped. Without that
     * information nothing is called lossless.
     *
     * Anything it cannot parse with certainty — `SELECT *`, an expression
     * where a column name belongs — is left out too, so the statement
     * falls through to the ordinary rules and is still destructive.
     *
     * @param  list<string>  $sql
     * @return array{list<string>, list<string>} rebuilt tables, scratch tables
     */
    private function losslessRebuilds(array $sql): array
    {
        $rebuilt = $scratchTables = [];

        foreach ($sql as $statement) {
            if (! preg_match('/^\s*CREATE\s+TEMPORARY\s+TABLE\s+(\S+)\s+AS\s+SELECT\s+(.+?)\s+FROM\s+(\S+)/is', $statement, $m)) {
                continue;
            }

            $table = $this->identifier($m[3]);
            $parked = $this->columnList($m[2]);

            if ($parked === null || ! isset($this->columnsNow[$table])) {
                continue;
            }

            // A column the table has but the rebuild does not park is a
            // column that will not exist afterwards.
            if (array_diff($this->columnsNow[$table], $parked) !== []) {
                continue;
            }

            $rebuilt[] = $table;
            $scratchTables[] = $this->identifier($m[1]);
        }

        return [array_values(array_unique($rebuilt)), array_values(array_unique($scratchTables))];
    }

    /**
     * Splits a SELECT list into plain column names, or gives up.
     *
     * Returning null on anything that is not a bare identifier is the
     * point: a function call or a literal means the mapping between the
     * two lists cannot be trusted, and a wrong "nothing was lost" here
     * would be worse than an unnecessary prompt for --allow-destructive.
     *
     * @return list<string>|null
     */
    private function columnList(string $list): ?array
    {
        $columns = [];

        foreach (explode(',', $list) as $part) {
            $column = $this->identifier($part);

            if ($column === '' || ! preg_match('/^[A-Z0-9_]+$/', $column)) {
                return null;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    private function identifier(string $raw): string
    {
        return strtoupper(trim(trim($raw), '`"[]\';'));
    }
}
