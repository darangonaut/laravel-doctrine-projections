<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shadow;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'flags')]
class Flag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    public string $name = '';

    /** `exists` is a public property on Eloquent's Model. */
    #[ORM\Column(name: 'exists', type: 'boolean')]
    public bool $active = false;
}
