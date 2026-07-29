<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

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
     * emits DROP TABLE on a table we own. Treating that as fatal makes the
     * driver unusable — no migration could ever be generated on it.
     */
    #[Test]
    public function sqlite_table_rebuild_is_destructive_not_fatal(): void
    {
        [$fatal, $destructive] = $this->classify(
            'CREATE TEMPORARY TABLE __temp__books AS SELECT id, title FROM books',
            'DROP TABLE books',
            'CREATE TABLE books (id INTEGER NOT NULL, title VARCHAR(200) NOT NULL, PRIMARY KEY(id))',
            'INSERT INTO books (id, title) SELECT id, title FROM __temp__books',
            'DROP TABLE __temp__books',
        );

        self::assertSame([], $fatal, 'a SQLite rebuild must not be reported as a filter failure');
        self::assertCount(2, $destructive);
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
