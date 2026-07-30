<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes;

use Doctrine\ORM\Mapping as ORM;

/**
 * Identifier length limits differ per driver — MySQL stops at 64
 * characters, PostgreSQL truncates at 63.
 */
#[ORM\Entity]
#[ORM\Table(name: 'measurement_station_daily_aggregate_readings_by_region')]
class LongName
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'average_relative_humidity_percentage_reading', type: 'integer')]
    public int $averageRelativeHumidity = 0;
}
