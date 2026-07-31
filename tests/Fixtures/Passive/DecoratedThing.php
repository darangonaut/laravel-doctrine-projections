<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Passive;

use Doctrine\ORM\Mapping as ORM;

/**
 * The same three columns as PlainThing, wearing every Doctrine feature
 * that concerns the write side or the schema but not the shape of a row.
 */
#[ORM\Entity(repositoryClass: ThingRepository::class)]
#[ORM\Table(name: 'decorated_things')]
#[ORM\Index(name: 'decorated_label_idx', columns: ['label'])]
#[ORM\UniqueConstraint(name: 'decorated_label_uq', columns: ['label', 'active'])]
#[ORM\Cache(usage: 'READ_ONLY')]
#[ORM\HasLifecycleCallbacks]
#[ORM\EntityListeners([ThingListener::class])]
class DecoratedThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $label = '';

    #[ORM\Column(type: 'boolean')]
    public bool $active = true;

    #[ORM\PrePersist]
    public function stampOnCreate(): void
    {
        $this->active = true;
    }
}
