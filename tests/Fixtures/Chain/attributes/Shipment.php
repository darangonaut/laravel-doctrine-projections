<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Chain\Attributes;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Chain\Xml\Carrier;
use Doctrine\ORM\Mapping as ORM;

/** Mapped by attributes, pointing at an entity mapped by XML. */
#[ORM\Entity]
#[ORM\Table(name: 'shipments')]
class Shipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    public string $tracking = '';

    #[ORM\ManyToOne(targetEntity: Carrier::class)]
    #[ORM\JoinColumn(name: 'carrier_id', referencedColumnName: 'id')]
    public ?Carrier $carrier = null;
}
