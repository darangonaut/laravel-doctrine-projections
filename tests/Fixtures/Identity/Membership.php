<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Identity;

use Doctrine\ORM\Mapping as ORM;

/** The association itself is part of the key — Doctrine allows this. */
#[ORM\Entity]
#[ORM\Table(name: 'memberships')]
class Membership
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_iso', referencedColumnName: 'iso')]
    public ?Country $country = null;

    #[ORM\Id]
    #[ORM\Column(name: 'org', type: 'string', length: 20)]
    public string $org = '';
}
