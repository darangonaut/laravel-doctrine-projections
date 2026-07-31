<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Car extends Vehicle
{
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $doors = null;
}
