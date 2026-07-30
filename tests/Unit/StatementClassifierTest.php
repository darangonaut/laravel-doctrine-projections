<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Schema\ClassifiedStatements;
use Darangonaut\DoctrineProjections\Schema\StatementClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The guard originally matched only a leading "DROP TABLE", so a column
 * drop sailed into a migration with an empty down() and the data was
 * gone. This test keeps the classification honest.
 */
final class StatementClassifierTest extends TestCase
{
    /** Tables the "application" maps in these tests. */
    private const array OWNED = ['books', 'authors', 'genres', 'book_genre'];

    /** @return array{list<string>, list<string>, list<string>} */
    private function classify(string ...$sql): array
    {
        $result = (new StatementClassifier(self::OWNED))->classify(array_values($sql));

        return [$result->fatal, $result->destructive, $result->warnings];
    }

    /**
     * The five statements DBAL emits to change a column on SQLite.
     *
     * @param  string  $parked  columns saved into the scratch table
     * @return list<string>
     */
    private function rebuild(string $parked): array
    {
        return [
            "CREATE TEMPORARY TABLE __temp__books AS SELECT {$parked} FROM books",
            'DROP TABLE books',
            'CREATE TABLE books (id INTEGER NOT NULL, PRIMARY KEY(id))',
            "INSERT INTO books (id, whatever) SELECT {$parked} FROM __temp__books",
            'DROP TABLE __temp__books',
        ];
    }

    /**
     * @param  list<string>  $sql
     * @param  list<string>  $booksHasNow
     */
    private function withColumns(array $sql, array $booksHasNow): ClassifiedStatements
    {
        return (new StatementClassifier(self::OWNED, ['books' => $booksHasNow]))->classify($sql);
    }

    #[Test]
    public function dropping_a_table_we_do_not_own_is_fatal(): void
    {
        // exactly what a broken schema filter looks like
        [$fatal, $destructive, $warnings] = $this->classify('DROP TABLE users');

        self::assertCount(1, $fatal);
        self::assertSame([], $destructive);
        self::assertSame([], $warnings);
    }

    /**
     * SQLite cannot alter a column except by rebuilding the table, so DBAL
     * emits DROP TABLE on a table we own. Read one statement at a time that
     * looks like total loss, when the next statement puts every row back —
     * so a rename used to demand --allow-destructive for nothing.
     */
    #[Test]
    public function a_rebuild_that_parks_every_existing_column_needs_no_consent(): void
    {
        // the table has exactly what the rebuild carries: a rename
        $result = $this->withColumns($this->rebuild('id, title'), ['id', 'title']);

        self::assertSame([], $result->fatal);
        self::assertSame([], $result->destructive, 'nothing was lost, so nothing to consent to');
        self::assertSame(['BOOKS'], $result->rebuiltTables);
    }

    /**
     * The one that matters. DBAL omits a dropped column from every
     * statement, so a drop and a rename are textually identical — the
     * only thing that tells them apart is what the table holds now.
     */
    #[Test]
    public function a_rebuild_that_leaves_an_existing_column_behind_is_destructive(): void
    {
        // identical SQL to the test above; only the table differs
        $result = $this->withColumns($this->rebuild('id, title'), ['id', 'title', 'subtitle']);

        self::assertSame([], $result->fatal);
        self::assertCount(2, $result->destructive);
        self::assertSame([], $result->rebuiltTables);
    }

    #[Test]
    public function without_knowing_the_table_no_rebuild_is_called_lossless(): void
    {
        // no column information at all — the safe answer is the old one
        $result = (new StatementClassifier(self::OWNED))->classify($this->rebuild('id, title'));

        self::assertCount(2, $result->destructive);
        self::assertSame([], $result->rebuiltTables);
    }

    /**
     * The parser refuses to guess. Anything it cannot read as a plain
     * column list falls through to the ordinary rules, because claiming
     * "nothing was lost" wrongly is far worse than an extra prompt.
     */
    #[Test]
    public function an_unreadable_column_list_falls_back_to_destructive(): void
    {
        foreach (['*', 'id, COALESCE(title, 0)', "id, 'literal'"] as $unreadable) {
            $result = $this->withColumns($this->rebuild($unreadable), ['id', 'title']);

            self::assertCount(2, $result->destructive, "list: {$unreadable}");
            self::assertSame([], $result->rebuiltTables);
        }
    }

    #[Test]
    public function rebuild_detection_survives_lowercase_and_quoted_identifiers(): void
    {
        $result = $this->withColumns([
            'create temporary table __temp__books as select "id", "title" from "books"',
            'drop table "books"',
            'insert into "books" ("id", "name") select "id", "title" from __temp__books',
            'drop table __temp__books',
        ], ['ID', 'Title']);

        self::assertSame([], $result->destructive);
        self::assertSame(['BOOKS'], $result->rebuiltTables);
    }

    #[Test]
    public function quoted_and_if_exists_table_names_are_recognised(): void
    {
        [$fatal, $destructive] = $this->classify(
            'DROP TABLE IF EXISTS `books`',
            'DROP TABLE "authors"',
            'DROP TABLE IF EXISTS `sessions`',
        );

        self::assertCount(1, $fatal, 'we do not own sessions');
        self::assertCount(2, $destructive);
    }

    /**
     * Making a column nullable is lossless on PostgreSQL, but the regex
     * read it as a column drop and the command failed with "destroys data".
     */
    #[Test]
    public function postgres_nullability_and_default_changes_are_not_destructive(): void
    {
        [$fatal, $destructive, $warnings] = $this->classify(
            'ALTER TABLE books ALTER published_at DROP NOT NULL',
            'ALTER TABLE books ALTER COLUMN page_count DROP DEFAULT',
            'ALTER TABLE books ALTER id DROP IDENTITY',
        );

        self::assertSame([], $fatal);
        self::assertSame([], $destructive);
        self::assertCount(3, $warnings);
    }

    #[Test]
    public function lowercase_sql_is_classified_the_same(): void
    {
        [$fatal, $destructive] = $this->classify(
            'drop table users',
            'alter table books drop page_count',
        );

        self::assertCount(1, $fatal);
        self::assertCount(1, $destructive);
    }

    #[Test]
    public function column_drop_is_destructive(): void
    {
        [$fatal, $destructive] = $this->classify(
            'ALTER TABLE books DROP page_count',
            'ALTER TABLE books DROP COLUMN subtitle',
        );

        self::assertSame([], $fatal);
        self::assertCount(2, $destructive);
    }

    #[Test]
    public function truncate_is_destructive(): void
    {
        [, $destructive] = $this->classify('TRUNCATE books');

        self::assertCount(1, $destructive);
    }

    #[Test]
    public function constraint_and_index_drops_are_warnings_not_data_loss(): void
    {
        [$fatal, $destructive, $warnings] = $this->classify(
            'ALTER TABLE orders DROP FOREIGN KEY `orders_customer_id_foreign`',
            'DROP INDEX orders_customer_id_status_index ON orders',
            'ALTER TABLE books DROP INDEX books_isbn_unique',
        );

        self::assertSame([], $fatal);
        self::assertSame([], $destructive);
        self::assertCount(3, $warnings);
    }

    #[Test]
    public function change_and_modify_are_warnings(): void
    {
        [, $destructive, $warnings] = $this->classify(
            'ALTER TABLE books CHANGE title title VARCHAR(50) NOT NULL',
            'ALTER TABLE books MODIFY page_count SMALLINT NOT NULL',
        );

        self::assertSame([], $destructive);
        self::assertCount(2, $warnings);
    }

    #[Test]
    public function multi_clause_alter_with_a_column_drop_is_destructive(): void
    {
        // one ALTER may carry several clauses — a column drop must not
        // hide behind a harmless DROP FOREIGN KEY in front of it
        [, $destructive] = $this->classify(
            'ALTER TABLE books DROP FOREIGN KEY fk_x, DROP page_count',
        );

        self::assertCount(1, $destructive);
    }

    #[Test]
    public function additive_statements_pass_clean(): void
    {
        [$fatal, $destructive, $warnings] = $this->classify(
            'CREATE TABLE genres (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id))',
            'ALTER TABLE books ADD subtitle VARCHAR(200) DEFAULT NULL',
            'ALTER TABLE book_genre ADD CONSTRAINT FK_1 FOREIGN KEY (book_id) REFERENCES books (id)',
            'CREATE UNIQUE INDEX books_isbn_unique ON books (isbn)',
        );

        self::assertSame([], $fatal);
        self::assertSame([], $destructive);
        self::assertSame([], $warnings);
    }
}
