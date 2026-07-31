<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Overrides;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'editors')]
class Editor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $name = '';
}
