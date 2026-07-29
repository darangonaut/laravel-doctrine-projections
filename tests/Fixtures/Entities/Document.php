<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Entities;

use Doctrine\ORM\Mapping as ORM;

/** String (UUID) key without identity, plus a self-referencing relation. */
#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\Column(name: 'uuid', type: 'string', length: 36)]
    private string $uuid;

    #[ORM\Column(name: 'title', type: 'string', length: 200)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?Document $parent = null;
}
