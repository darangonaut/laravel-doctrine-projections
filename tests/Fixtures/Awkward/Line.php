<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Awkward;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_lines')]
class Line
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'popis', type: 'string', length: 40)]
    public string $description = '';

    #[ORM\ManyToOne(targetEntity: Report::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'report_id')]
    public ?Report $report = null;
}
