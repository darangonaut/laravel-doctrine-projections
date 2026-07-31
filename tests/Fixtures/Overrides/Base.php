<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Overrides;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class Base
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'title', type: 'string', length: 100)]
    public string $title = '';
}
