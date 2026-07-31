<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Advanced;

use Doctrine\ORM\Mapping as ORM;

/** An embeddable inside an embeddable — columns two levels down. */
#[ORM\Embeddable]
class Place
{
    #[ORM\Column(type: 'string', length: 80)]
    public string $label = '';

    #[ORM\Embedded(class: Coordinates::class, columnPrefix: 'coord_')]
    public Coordinates $coordinates;

    public function __construct()
    {
        $this->coordinates = new Coordinates;
    }
}
