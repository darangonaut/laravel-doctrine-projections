<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Embedded;

use Doctrine\ORM\Mapping as ORM;

/** No table of its own — these columns live on whatever embeds it. */
#[ORM\Embeddable]
class Address
{
    #[ORM\Column(type: 'string', length: 120)]
    public string $street = '';

    #[ORM\Column(type: 'string', length: 80)]
    public string $city = '';

    #[ORM\Column(name: 'postal_code', type: 'string', length: 10, nullable: true)]
    public ?string $postalCode = null;
}
