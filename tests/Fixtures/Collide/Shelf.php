<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Collide;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'collide_shelves')]
class Shelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, HasMany> */
    #[ORM\OneToMany(targetEntity: HasMany::class, mappedBy: 'shelf')]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection;
    }
}
