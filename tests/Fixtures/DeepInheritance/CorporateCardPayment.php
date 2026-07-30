<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\DeepInheritance;

use Doctrine\ORM\Mapping as ORM;

/** A card payment too — Doctrine returns it from CardPayment queries. */
#[ORM\Entity]
class CorporateCardPayment extends CardPayment
{
    #[ORM\Column(name: 'cost_centre', type: 'string', length: 20, nullable: true)]
    public ?string $costCentre = null;
}
