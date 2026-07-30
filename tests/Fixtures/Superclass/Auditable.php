<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Superclass;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** No table of its own; whatever extends it gets these columns. */
#[ORM\MappedSuperclass]
abstract class Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'created_by', type: 'string', length: 60, nullable: true)]
    public ?string $createdBy = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable('2026-07-30 09:00:00');
    }
}
