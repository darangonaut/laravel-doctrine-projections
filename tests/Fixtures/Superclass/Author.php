<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Superclass;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'authors')]
class Author extends Auditable
{
    #[ORM\Column(type: 'string', length: 60)]
    public string $name = '';
}
