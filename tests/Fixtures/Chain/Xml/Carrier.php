<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Chain\Xml;

/** Not one attribute on it — everything it knows lives in the XML. */
class Carrier
{
    public ?int $id = null;

    public string $name = '';

    public bool $express = false;
}
