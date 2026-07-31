<?php

declare(strict_types=1);

// No namespace at all — an entity from a codebase that predates them, or
// one generated into the root.

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'global_things')]
class GlobalThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'label', type: 'string', length: 40)]
    public string $label = '';
}
