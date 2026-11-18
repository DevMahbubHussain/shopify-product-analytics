<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Stringable;

final readonly class ShopifyGid implements Stringable
{
    private function __construct(public string $type, public string $id)
    {
    }

    public static function pattern(string $type): string
    {
        return '/^gid:\/\/shopify\/' . preg_quote($type, '/') . '\/[0-9]+$/';
    }

    public static function parse(string $value, string $expectedType): self
    {
        if (preg_match(self::pattern($expectedType), $value) !== 1) {
            throw new InvalidArgumentException("Expected a Shopify {$expectedType} GID, got [{$value}].");
        }

        return new self($expectedType, substr($value, strrpos($value, '/') + 1));
    }

    public static function parseNullable(?string $value, string $expectedType): ?self
    {
        return $value === null ? null : self::parse($value, $expectedType);
    }

    public function __toString(): string
    {
        return "gid://shopify/{$this->type}/{$this->id}";
    }
}
