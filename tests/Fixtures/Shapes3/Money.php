<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3;

use Doctrine\ORM\Mapping as ORM;

/** An embeddable whose columns get no prefix at all. */
#[ORM\Embeddable]
class Money
{
    #[ORM\Column(name: 'amount_minor', type: 'integer')]
    public int $minor = 0;

    #[ORM\Column(name: 'currency', type: 'string', length: 3)]
    public string $currency = 'EUR';
}
