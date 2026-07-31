<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\UnmappedSubclass;

use Doctrine\ORM\Mapping as ORM;

/** Abstract, in no map, and nothing concrete extends it. */
#[ORM\Entity]
abstract class Truck extends Vehicle {}
