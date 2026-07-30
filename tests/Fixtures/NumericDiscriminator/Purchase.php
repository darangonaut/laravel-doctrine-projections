<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\NumericDiscriminator;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Purchase extends Event
{
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $cents = null;
}
