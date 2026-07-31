<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Awkward;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Names at their limits and mapping options that concern the write side.
 *
 * The table name is 63 characters — PostgreSQL's identifier limit, and
 * one over what a shorter limit would allow. Two columns carry diacritics.
 * `orphanRemoval` and `fetch: EAGER` are both about what Doctrine does
 * when writing or loading, and neither should reach the projection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'a_table_name_that_is_exactly_sixty_three_characters_long_abcdef')]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'mesíc', type: 'string', length: 20)]
    public string $month = '';

    #[ORM\Column(name: 'druhá_hodnota', type: 'integer')]
    public int $second = 0;

    #[ORM\Column(name: 'total_including_tax_and_shipping_and_handling_and_discounts', type: 'integer')]
    public int $total = 0;

    /** @var Collection<int, Line> */
    #[ORM\OneToMany(targetEntity: Line::class, mappedBy: 'report', orphanRemoval: true, fetch: 'EAGER')]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection;
    }
}
