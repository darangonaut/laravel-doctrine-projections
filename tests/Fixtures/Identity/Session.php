<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Identity;

use Doctrine\ORM\Mapping as ORM;

/** A `guid` key — Doctrine's own type, not a plain string. */
#[ORM\Entity]
#[ORM\Table(name: 'sessions')]
class Session
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    public string $id = '';

    #[ORM\Column(name: 'ip', type: 'string', length: 45)]
    public string $ip = '';
}
