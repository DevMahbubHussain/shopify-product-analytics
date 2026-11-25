<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use DateTimeInterface;

/**
 * For timestamptz(6) columns. Model writes use getDateFormat(); query-builder
 * update() calls bypass the model, so pass values through microsecondTimestamp().
 */
trait StoresMicrosecondTimestamps
{
    private const MICROSECOND_DATE_FORMAT = 'Y-m-d H:i:s.uP';

    public function getDateFormat(): string
    {
        return self::MICROSECOND_DATE_FORMAT;
    }

    public static function microsecondTimestamp(DateTimeInterface $at): string
    {
        return $at->format(self::MICROSECOND_DATE_FORMAT);
    }
}
