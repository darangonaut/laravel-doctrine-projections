<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Joined;

use Doctrine\ORM\Mapping as ORM;

/** Class table inheritance — an entity spread across several tables. */
#[ORM\Entity]
#[ORM\Table(name: 'vehicles')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['car' => Car::class])]
abstract class Vehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;
}
