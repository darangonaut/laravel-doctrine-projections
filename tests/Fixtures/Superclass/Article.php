<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Superclass;

use Doctrine\ORM\Mapping as ORM;

/** Inherits the audit columns and adds an association of its own. */
#[ORM\Entity]
#[ORM\Table(name: 'articles')]
class Article extends Auditable
{
    #[ORM\Column(type: 'string', length: 200)]
    public string $title = '';

    #[ORM\ManyToOne(targetEntity: Author::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    public ?Author $author = null;
}
