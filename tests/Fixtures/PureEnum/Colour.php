<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\PureEnum;

/** No backing type — the point of the fixture. */
enum Colour
{
    case Red;
    case Blue;
}
