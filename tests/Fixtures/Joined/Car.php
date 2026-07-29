<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Joined;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cars')]
class Car extends Vehicle
{
    #[ORM\Column(name: 'doors', type: 'integer')]
    private int $doors;
}
