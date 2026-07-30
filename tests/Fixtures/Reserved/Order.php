<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Reserved;

use Doctrine\ORM\Mapping as ORM;

/**
 * `order` is a reserved SQL word, and Doctrine's way of saying "quote
 * this" is to wrap the name in backticks in the mapping itself. A column
 * called `key` is the same problem one level down.
 */
#[ORM\Entity]
#[ORM\Table(name: '`order`')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: '`key`', type: 'string', length: 40)]
    public string $key = '';

    #[ORM\Column(type: 'integer')]
    public int $total = 0;
}
