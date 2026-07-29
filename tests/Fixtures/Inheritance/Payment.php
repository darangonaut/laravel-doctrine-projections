<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Inheritance;

use Doctrine\ORM\Mapping as ORM;

/** Single table inheritance — all subclasses live in `payments`. */
#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['card' => CardPayment::class, 'cash' => CashPayment::class])]
abstract class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'amount', type: 'integer')]
    private int $amount;
}
