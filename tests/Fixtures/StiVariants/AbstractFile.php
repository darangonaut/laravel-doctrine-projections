<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants;

use Doctrine\ORM\Mapping as ORM;

/** In the middle of the hierarchy and never instantiated — no discriminator value. */
#[ORM\Entity]
abstract class AbstractFile extends Node
{
    /**
     * A subclass column can only be nullable in single table inheritance:
     * the other subclasses share the table and have nothing to put here.
     */
    #[ORM\Column(name: 'size_bytes', type: 'integer', nullable: true)]
    public ?int $sizeBytes = null;
}
