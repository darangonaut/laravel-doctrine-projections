<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\NumericDiscriminator;

use Doctrine\ORM\Mapping as ORM;

/** The discriminator is an integer column, not a string. */
#[ORM\Entity]
#[ORM\Table(name: 'events')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'integer')]
#[ORM\DiscriminatorMap([1 => Login::class, 2 => Purchase::class])]
abstract class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 60)]
    public string $actor = '';
}
