<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Entities;

use Doctrine\ORM\Mapping as ORM;

/** String (UUID) key without identity, plus a self-referencing relation. */
#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\Column(name: 'uuid', type: 'string', length: 36)]
    private string $uuid;

    #[ORM\Column(name: 'title', type: 'string', length: 200)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?Document $parent = null;

    /**
     * Deliberately two words. Every other relation in these fixtures is a
     * single word, where camelCase and snake_case look the same — which is
     * how the generated docblock came to advertise `$replaced_by`, a
     * property Eloquent does not have.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replaced_by_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?Document $replacedBy = null;
}
