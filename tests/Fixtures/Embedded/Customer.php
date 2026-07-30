<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Embedded;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $name = '';

    #[ORM\Embedded(class: Address::class, columnPrefix: 'billing_')]
    public Address $billing;

    #[ORM\Embedded(class: Address::class, columnPrefix: false)]
    public Address $shipping;
}
