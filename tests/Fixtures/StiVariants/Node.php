<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants;

use Doctrine\ORM\Mapping as ORM;

/**
 * An abstract class sits in the middle of this hierarchy with no
 * discriminator value of its own.
 *
 * Mapping the discriminator column as a field too is not possible at all:
 * Doctrine refuses with "Duplicate definition of column", so there is
 * nothing for the generator to decide about.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nodes')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 20)]
#[ORM\DiscriminatorMap(['file' => TextFile::class, 'image' => ImageFile::class, 'folder' => Folder::class])]
abstract class Node
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $name = '';
}
