<?php

declare(strict_types=1);

namespace App\Analytics\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class StoreTimezoneService
{
    private const string DEFAULT_TIMEZONE = 'UTC';

    public function toStoreDate(string|DateTimeInterface $utcTimestamp, ?string $timezone): string
    {
        $targetZone = $this->resolveTimezone($timezone);

        $carbon = $utcTimestamp instanceof DateTimeInterface
            ? CarbonImmutable::instance($utcTimestamp)
            : CarbonImmutable::parse($utcTimestamp, 'UTC');

        return $carbon->setTimezone($targetZone)->toDateString();
    }

    public function startOfDayUtc(string $storeDate, ?string $timezone): CarbonImmutable
    {
        $targetZone = $this->resolveTimezone($timezone);

        $storeStart = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$storeDate} 00:00:00", $targetZone);

        return $storeStart->setTimezone('UTC');
    }

    public function endOfDayUtc(string $storeDate, ?string $timezone): CarbonImmutable
    {
        $targetZone = $this->resolveTimezone($timezone);

        $storeEnd = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$storeDate} 23:59:59", $targetZone);

        return $storeEnd->setTimezone('UTC');
    }

    public function startOfNextDayUtc(string $storeDate, ?string $timezone): CarbonImmutable
    {
        $targetZone = $this->resolveTimezone($timezone);

        $storeStart = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$storeDate} 00:00:00", $targetZone);

        return $storeStart->addDay()->startOfDay()->setTimezone('UTC');
    }

    public function resolveTimezone(?string $timezone): DateTimeZone
    {
        if ($timezone === null || trim($timezone) === '') {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }

        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }
}
