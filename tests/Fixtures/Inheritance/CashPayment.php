<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CashPayment extends Payment
{
    #[ORM\Column(name: 'received_by', type: 'string', length: 60, nullable: true)]
    private ?string $receivedBy = null;
}
