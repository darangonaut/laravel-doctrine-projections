<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\JoinTable\Book;
use Darangonaut\DoctrineProjections\Tests\Fixtures\JoinTable\Genre;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Both sides of a ManyToMany. The inverse side is the interesting one:
 * the generator has to swap the join columns, and getting that backwards
 * returns a plausible-looking set of the wrong rows.
 */
final class ManyToManyDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('JoinTable', 'DifferentialM2M'.getmypid());
        $this->compare = new Compare($this->harness);

        $novel = $this->genre('Román');
        $poetry = $this->genre('Poézia');
        $essay = $this->genre('Esej');

        // deliberately uneven: one book in two genres, one in one, and a
        // genre with no books at all
        $this->book('Solaris', [$novel, $essay]);
        $this->book('Kvety zla', [$poetry]);

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function genre(string $name): Genre
    {
        $genre = new Genre;
        $genre->name = $name;

        $this->harness->em()->persist($genre);

        return $genre;
    }

    /** @param list<Genre> $genres */
    private function book(string $title, array $genres): Book
    {
        $book = new Book;
        $book->title = $title;

        foreach ($genres as $genre) {
            $book->genres->add($genre);
            $genre->books->add($book);
        }

        $this->harness->em()->persist($book);

        return $book;
    }

    #[Test]
    public function every_column_agrees(): void
    {
        $this->compare->columns(Book::class);
        $this->compare->columns(Genre::class);
    }

    #[Test]
    public function the_owning_side_agrees(): void
    {
        $this->compare->associations(Book::class);
    }

    #[Test]
    public function the_inverse_side_agrees(): void
    {
        $this->compare->associations(Genre::class);
    }
}
