<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Surface;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'authors')]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 60)]
    public string $name = '';

    /** A column Eloquent would treat specially only with SoftDeletes. */
    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, Post> */
    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'author')]
    public Collection $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection;
    }
}
