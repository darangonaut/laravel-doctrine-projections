<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tracks')]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $title = '';

    #[ORM\Column(type: 'integer')]
    public int $position = 0;

    /** Field `discNumber`, column `disc_number`. */
    #[ORM\Column(name: 'disc_number', type: 'integer')]
    public int $discNumber = 1;

    #[ORM\ManyToOne(targetEntity: Album::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false)]
    public ?Album $album = null;
}
