<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes;

use Doctrine\ORM\Mapping as ORM;

/** Nothing but a key — a join-only table, or a queue of ids. */
#[ORM\Entity]
#[ORM\Table(name: 'markers')]
class Marker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;
}
