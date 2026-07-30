<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Pairs;

use Doctrine\ORM\Mapping as ORM;

/** Owning side — holds account_id. */
#[ORM\Entity]
#[ORM\Table(name: 'profiles')]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    public ?string $bio = null;

    #[ORM\OneToOne(targetEntity: Account::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false)]
    public ?Account $account = null;
}
