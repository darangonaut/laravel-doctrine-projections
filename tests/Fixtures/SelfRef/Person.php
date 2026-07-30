<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\SelfRef;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Both sides of the join table point at the same table, so the generator
 * cannot tell them apart by target class — only by which side it is on.
 */
#[ORM\Entity]
#[ORM\Table(name: 'people')]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 60)]
    public string $name = '';

    /** @var Collection<int, Person> */
    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'followers')]
    #[ORM\JoinTable(name: 'follows')]
    #[ORM\JoinColumn(name: 'follower_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'followed_id', referencedColumnName: 'id')]
    public Collection $following;

    /** @var Collection<int, Person> */
    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'following')]
    public Collection $followers;

    public function __construct()
    {
        $this->following = new ArrayCollection;
        $this->followers = new ArrayCollection;
    }
}
