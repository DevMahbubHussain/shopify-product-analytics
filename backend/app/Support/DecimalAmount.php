<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/** Non-negative money amount kept as a string; DECIMAL(20,6) allows 14 integer and 6 fractional digits. */
final readonly class DecimalAmount implements Stringable
{
    public const PATTERN = '/^[0-9]{1,14}(\.[0-9]{1,6})?$/';

    private function __construct(public string $value)
    {
    }

    public static function parse(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid decimal amount [{$value}].");
        }

        return new self($value);
    }

    public static function parseNullable(?string $value): ?self
    {
        return $value === null ? null : self::parse($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
