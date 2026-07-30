<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\NumericDiscriminator;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Login extends Event
{
    #[ORM\Column(name: 'ip', type: 'string', length: 45, nullable: true)]
    public ?string $ip = null;
}
