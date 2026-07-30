<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\JoinTable;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Owning side of the ManyToMany: this is where `book_genre` is defined. */
#[ORM\Entity]
#[ORM\Table(name: 'books')]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 200)]
    public string $title = '';

    /** @var Collection<int, Genre> */
    #[ORM\ManyToMany(targetEntity: Genre::class, inversedBy: 'books')]
    #[ORM\JoinTable(name: 'book_genre')]
    public Collection $genres;

    public function __construct()
    {
        $this->genres = new ArrayCollection;
    }
}
