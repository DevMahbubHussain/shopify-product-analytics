<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final class IngestionRules
{
    public const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    /** @return list<string> */
    public static function gid(string $type, bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:128', 'regex:'.ShopifyGid::pattern($type)];
    }

    /** @return list<string> */
    public static function amount(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:'.DecimalAmount::PATTERN];
    }

    /** @return list<string> */
    public static function timestamp(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:'.self::TIMESTAMP_PATTERN, 'date'];
    }

    /** @return list<string> */
    public static function currency(): array
    {
        return ['required', 'string', 'regex:/^[A-Z]{3}$/'];
    }

    public static function toUtc(?string $timestamp): ?CarbonImmutable
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->utc();
    }
}
