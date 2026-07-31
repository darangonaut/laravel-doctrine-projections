<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Overrides;

use Doctrine\ORM\Mapping as ORM;

/** Renames an inherited column without touching the superclass. */
#[ORM\Entity]
#[ORM\Table(name: 'articles')]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'title', column: new ORM\Column(name: 'headline', type: 'string', length: 200)),
])]
class Article extends Base
{
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $body = null;
}
