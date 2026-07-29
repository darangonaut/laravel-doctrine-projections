<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Duplicate\Sales;

use Doctrine\ORM\Mapping as ORM;

/** Same short name as Billing\Report — the projections would overwrite each other. */
#[ORM\Entity]
#[ORM\Table(name: 'sales_reports')]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;
}
