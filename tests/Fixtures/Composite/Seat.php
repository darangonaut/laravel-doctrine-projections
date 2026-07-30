<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Composite;

use Doctrine\ORM\Mapping as ORM;

/** Two-column primary key — Eloquent has no support for this. */
#[ORM\Entity]
#[ORM\Table(name: 'seats')]
class Seat
{
    #[ORM\Id]
    #[ORM\Column(name: 'row_letter', type: 'string', length: 2)]
    public string $row = '';

    #[ORM\Id]
    #[ORM\Column(name: 'seat_number', type: 'integer')]
    public int $number = 0;

    #[ORM\Column(type: 'boolean')]
    public bool $occupied = false;
}
