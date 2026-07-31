<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass;

use Doctrine\ORM\Mapping as ORM;

/**
 * Abstract, so Doctrine allows it to be missing from the DiscriminatorMap
 * — the exception it would otherwise throw says so itself.
 */
#[ORM\Entity]
abstract class Van extends Vehicle
{
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $pallets = null;
}
