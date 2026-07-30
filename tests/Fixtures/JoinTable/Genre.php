<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\JoinTable;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Inverse side: names no join table of its own. */
#[ORM\Entity]
#[ORM\Table(name: 'genres')]
class Genre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';

    /** @var Collection<int, Book> */
    #[ORM\ManyToMany(targetEntity: Book::class, mappedBy: 'genres')]
    public Collection $books;

    public function __construct()
    {
        $this->books = new ArrayCollection;
    }
}
