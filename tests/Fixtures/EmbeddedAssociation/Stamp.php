<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\EmbeddedAssociation;

use Doctrine\ORM\Mapping as ORM;

/** An embeddable that tries to hold an association — Doctrine says no. */
#[ORM\Embeddable]
class Stamp
{
    #[ORM\Column]
    public string $note = '';

    #[ORM\ManyToOne(targetEntity: Actor::class)]
    public ?Actor $by = null;
}
