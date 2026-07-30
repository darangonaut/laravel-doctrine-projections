<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Indexed;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'settings')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'setting_key', type: 'string', length: 60)]
    public string $key = '';

    #[ORM\Column(type: 'string', length: 200)]
    public string $value = '';

    #[ORM\ManyToOne(targetEntity: Config::class, inversedBy: 'settings')]
    #[ORM\JoinColumn(name: 'config_id', referencedColumnName: 'id', nullable: false)]
    public ?Config $config = null;
}
