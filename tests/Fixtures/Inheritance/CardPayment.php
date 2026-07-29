<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CardPayment extends Payment
{
    #[ORM\Column(name: 'last_four', type: 'string', length: 4, nullable: true)]
    private ?string $lastFour = null;
}
