<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Advanced;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Coordinates
{
    #[ORM\Column(type: 'string', length: 20)]
    public string $lat = '';

    #[ORM\Column(type: 'string', length: 20)]
    public string $lng = '';
}
