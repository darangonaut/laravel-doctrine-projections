<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Entities;

use Doctrine\ORM\Mapping as ORM;

/** Owning side of a OneToOne — holds the account_id FK. */
#[ORM\Entity]
#[ORM\Table(name: 'profiles')]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Account::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false)]
    private Account $account;

    #[ORM\Column(name: 'bio', type: 'string', length: 500, nullable: true)]
    private ?string $bio = null;
}
