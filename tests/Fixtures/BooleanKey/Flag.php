<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\BooleanKey;

use Doctrine\ORM\Mapping as ORM;

/** A two-row lookup table keyed by the thing it describes. */
#[ORM\Entity]
#[ORM\Table(name: 'flags')]
class Flag
{
    #[ORM\Id]
    #[ORM\Column(name: 'enabled', type: 'boolean')]
    public bool $enabled = false;

    #[ORM\Column(name: 'label', type: 'string', length: 20)]
    public string $label = '';
}
