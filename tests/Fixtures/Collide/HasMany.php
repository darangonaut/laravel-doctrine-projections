<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Collide;

use Doctrine\ORM\Mapping as ORM;

/**
 * An entity literally named HasMany — its projection occupies that short
 * name, so the relation class can no longer be referenced bare.
 */
#[ORM\Entity]
#[ORM\Table(name: 'collide_hasmany')]
class HasMany
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shelf::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'shelf_id', referencedColumnName: 'id', nullable: false)]
    private Shelf $shelf;
}
