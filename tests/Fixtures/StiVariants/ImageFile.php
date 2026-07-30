<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\StiVariants;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ImageFile extends AbstractFile
{
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $width = null;
}
