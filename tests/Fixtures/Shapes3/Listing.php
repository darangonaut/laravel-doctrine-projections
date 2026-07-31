<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3;

use Doctrine\ORM\Mapping as ORM;

/** One row of every shape this round wanted to see. */
#[ORM\Entity]
#[ORM\Table(name: 'listings')]
class Listing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 10, enumType: Status::class)]
    public Status $status = Status::Draft;

    #[ORM\Column(type: 'integer', enumType: Priority::class)]
    public Priority $priority = Priority::Low;

    #[ORM\Column(name: 'code', type: 'ascii_string', length: 12)]
    public string $code = '';

    #[ORM\Column(name: 'views', type: 'smallint')]
    public int $views = 0;

    #[ORM\Column(name: 'ratio', type: 'float')]
    public float $ratio = 0.0;

    #[ORM\Column(name: 'thumbnail', type: 'blob', nullable: true)]
    public mixed $thumbnail = null;

    /** Written by the database, never by the entity. */
    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable', nullable: true, insertable: false, updatable: false)]
    public ?\DateTimeImmutable $importedAt = null;

    #[ORM\Embedded(class: Money::class, columnPrefix: false)]
    public Money $price;

    #[ORM\Embedded(class: Span::class, columnPrefix: 'run_')]
    public Span $run;

    public function __construct()
    {
        $this->price = new Money;
        $this->run = new Span;
    }
}
