<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Shapes3;

enum Status: string
{
    case Draft = 'draft';
    case Live = 'live';
}
