<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Casts;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** One row of every column type whose PHP shape the two sides can disagree on. */
#[ORM\Entity]
#[ORM\Table(name: 'readings')]
class Reading
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /** Above 2^53: an int cannot hold this without losing digits. */
    #[ORM\Column(type: 'bigint')]
    public string $counter = '0';

    /** Doctrine keeps this a string on purpose — money must not float. */
    #[ORM\Column(type: 'decimal', precision: 20, scale: 4)]
    public string $amount = '0';

    #[ORM\Column(name: 'taken_on', type: 'date_immutable')]
    public DateTimeImmutable $takenOn;

    #[ORM\Column(name: 'taken_at', type: 'time_immutable')]
    public DateTimeImmutable $takenAt;

    #[ORM\Column(name: 'recorded_at', type: 'datetimetz_immutable')]
    public DateTimeImmutable $recordedAt;

    #[ORM\Column(type: 'boolean')]
    public bool $valid = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $meta = [];

    /** @var list<string> */
    #[ORM\Column(name: 'tags', type: 'simple_array', nullable: true)]
    public ?array $tags = null;

    public function __construct()
    {
        $this->takenOn = new DateTimeImmutable('2026-07-30');
        $this->takenAt = new DateTimeImmutable('14:30:00');
        $this->recordedAt = new DateTimeImmutable('2026-07-30 14:30:00');
    }
}
