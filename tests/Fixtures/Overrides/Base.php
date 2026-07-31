<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Overrides;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class Base
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'title', type: 'string', length: 100)]
    public string $title = '';

    #[ORM\ManyToOne(targetEntity: Editor::class)]
    #[ORM\JoinColumn(name: 'editor_id', referencedColumnName: 'id')]
    public ?Editor $editor = null;
}
