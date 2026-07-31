<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Quoted;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine's own escape hatch for reserved words: a name wrapped in
 * backticks in the mapping. Every name here is a SQL keyword.
 */
#[ORM\Entity]
#[ORM\Table(name: '`order`')]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: '`select`', type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: '`group`', type: 'string', length: 60)]
    public string $label = '';
}
