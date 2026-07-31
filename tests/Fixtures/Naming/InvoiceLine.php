<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Naming;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Not one explicit name anywhere — the naming strategy decides them all. */
#[ORM\Entity]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    public string $productName = '';

    #[ORM\Column(type: 'integer')]
    public int $quantityOrdered = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: TaxRate::class)]
    public ?TaxRate $taxRate = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable('2026-07-31 08:00:00');
    }
}
