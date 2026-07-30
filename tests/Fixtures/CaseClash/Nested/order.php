<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\CaseClash\Nested;

use Doctrine\ORM\Mapping as ORM;

/** Same basename as ::Order once the filesystem is asked. */
#[ORM\Entity]
#[ORM\Table(name: 'legacy_orders')]
class order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;
}
