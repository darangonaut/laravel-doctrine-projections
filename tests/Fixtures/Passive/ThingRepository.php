<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Passive;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<DecoratedThing>
 */
class ThingRepository extends EntityRepository {}
