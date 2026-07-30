<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Composite;

use Doctrine\ORM\Mapping as ORM;

/**
 * Points at an entity whose key is two columns, so the association needs
 * two join columns. Eloquent's belongsTo takes exactly one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bookings')]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 80)]
    public string $passenger = '';

    #[ORM\ManyToOne(targetEntity: Seat::class)]
    #[ORM\JoinColumn(name: 'seat_row', referencedColumnName: 'row_letter')]
    #[ORM\JoinColumn(name: 'seat_no', referencedColumnName: 'seat_number')]
    public ?Seat $seat = null;
}
