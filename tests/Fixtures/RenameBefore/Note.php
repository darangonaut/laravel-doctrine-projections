<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\RenameBefore;

use Doctrine\ORM\Mapping as ORM;

/**
 * The table this maps to is created by hand in the test with `content`
 * where the mapping says `body`, so SchemaTool has a rename to resolve.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notes')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $title = '';

    #[ORM\Column(type: 'text')]
    public string $content = '';
}
