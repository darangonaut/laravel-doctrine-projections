<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Ordered;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'albums')]
class Album
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $title = '';

    /**
     * Two columns, opposite directions, and `releasedOn` is a field whose
     * column is named differently — ordering by the field name would ask
     * the database for a column that does not exist.
     *
     * @var Collection<int, Track>
     */
    #[ORM\OneToMany(targetEntity: Track::class, mappedBy: 'album')]
    #[ORM\OrderBy(['discNumber' => 'DESC', 'position' => 'ASC'])]
    public Collection $tracks;

    public function __construct()
    {
        $this->tracks = new ArrayCollection;
    }
}
