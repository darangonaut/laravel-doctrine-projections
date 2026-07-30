<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance;

use Doctrine\ORM\Mapping as ORM;

/** Three levels, one table. The middle class is the interesting one. */
#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap([
    'cash' => CashPayment::class,
    'card' => CardPayment::class,
    'corporate' => CorporateCardPayment::class,
])]
abstract class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'integer')]
    public int $amount = 0;
}
