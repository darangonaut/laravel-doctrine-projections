<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Passive;

use Doctrine\ORM\Mapping as ORM;

class ThingListener
{
    #[ORM\PostLoad]
    public function afterLoad(DecoratedThing $thing): void
    {
        // deliberately does nothing — its presence is the point
    }
}
