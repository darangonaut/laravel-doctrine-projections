<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TextFile extends AbstractFile
{
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    public ?string $encoding = null;
}
