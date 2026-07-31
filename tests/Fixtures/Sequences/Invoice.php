<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Sequences;

use Doctrine\ORM\Mapping as ORM;

/** A PostgreSQL sequence rather than an identity column. */
#[ORM\Entity]
#[ORM\Table(name: 'invoices')]
class Invoice
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'invoice_seq', allocationSize: 1)]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    public string $number = '';
}
