<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Identity;

use Doctrine\ORM\Mapping as ORM;

/** Assigned key — no generator at all. */
#[ORM\Entity]
#[ORM\Table(name: 'countries')]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(name: 'iso', type: 'string', length: 2)]
    public string $iso = '';

    #[ORM\Column(type: 'string', length: 80)]
    public string $name = '';
}
