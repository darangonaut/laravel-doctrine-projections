<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Schema;

use Doctrine\ORM\Mapping as ORM;

/** Lives in a schema of its own — routine on PostgreSQL. */
#[ORM\Entity]
#[ORM\Table(name: 'entries', schema: 'archive')]
class Archive
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $label = '';
}
