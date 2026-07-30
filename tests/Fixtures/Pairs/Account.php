<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Pairs;

use Doctrine\ORM\Mapping as ORM;

/** Inverse side of the OneToOne — the FK lives on profiles. */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    public string $email = '';

    #[ORM\OneToOne(targetEntity: Profile::class, mappedBy: 'account')]
    public ?Profile $profile = null;
}
