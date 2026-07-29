<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Entities;

use Doctrine\ORM\Mapping as ORM;

/** Inverse side of a OneToOne — the FK lives on profiles. */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'email', type: 'string', length: 180)]
    private string $email;

    #[ORM\OneToOne(targetEntity: Profile::class, mappedBy: 'account')]
    private ?Profile $profile = null;
}
