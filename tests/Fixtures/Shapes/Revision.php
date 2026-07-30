<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes;

use Doctrine\ORM\Mapping as ORM;

/** Self-referencing OneToOne, both sides, plus readonly properties. */
#[ORM\Entity]
#[ORM\Table(name: 'revisions')]
class Revision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    public string $label = '';

    #[ORM\OneToOne(targetEntity: self::class, inversedBy: 'supersededBy')]
    #[ORM\JoinColumn(name: 'supersedes_id', referencedColumnName: 'id', nullable: true)]
    public ?Revision $supersedes = null;

    #[ORM\OneToOne(targetEntity: self::class, mappedBy: 'supersedes')]
    public ?Revision $supersededBy = null;
}
