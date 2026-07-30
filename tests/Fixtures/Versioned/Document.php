<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Versioned;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Optimistic locking: Doctrine owns `version` and bumps it on write. */
#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $title = '';

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    public int $version = 1;

    #[ORM\Column(name: 'changed_at', type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $changedAt = null;
}
