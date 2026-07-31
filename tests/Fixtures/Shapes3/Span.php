<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Nullable on both ends — every column of it can be NULL. */
#[ORM\Embeddable]
class Span
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $from = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $to = null;
}
