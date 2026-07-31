<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Overrides;

use Doctrine\ORM\Mapping as ORM;

/**
 * Renames an inherited column and an inherited association's join column,
 * neither of which the superclass knows about.
 */
#[ORM\Entity]
#[ORM\Table(name: 'articles')]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'title', column: new ORM\Column(name: 'headline', type: 'string', length: 200)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(
        name: 'editor',
        joinColumns: [new ORM\JoinColumn(name: 'assigned_editor_id', referencedColumnName: 'id')],
    ),
])]
class Article extends Base
{
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $body = null;
}
