<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Custom;

/** A value object the database stores as "EUR 1234". */
final readonly class Money
{
    public function __construct(public string $currency, public int $cents) {}

    public function __toString(): string
    {
        return $this->currency.' '.$this->cents;
    }

    public static function fromString(string $value): self
    {
        [$currency, $cents] = explode(' ', $value, 2);

        return new self($currency, (int) $cents);
    }
}
