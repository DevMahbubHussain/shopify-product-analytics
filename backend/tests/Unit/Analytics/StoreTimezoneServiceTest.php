<?php

declare(strict_types=1);

use App\Analytics\Services\StoreTimezoneService;
use Carbon\CarbonImmutable;

it('converts UTC timestamp to store-local metric date across midnight boundaries', function () {
    $service = new StoreTimezoneService();

    // 23:30 UTC on March 31 is 08:30 on April 1 in Asia/Tokyo (+09:00)
    $utcMarch31Night = '2026-03-31T23:30:00Z';
    $tokyoDate = $service->toStoreDate($utcMarch31Night, 'Asia/Tokyo');
    expect($tokyoDate)->toBe('2026-04-01');

    // 02:00 UTC on January 1, 2026 is 21:00 on December 31, 2025 in America/New_York (-05:00)
    $utcJan1Morning = '2026-01-01T02:00:00Z';
    $newYorkDate = $service->toStoreDate($utcJan1Morning, 'America/New_York');
    expect($newYorkDate)->toBe('2025-12-31');
});

it('handles leap year date boundaries correctly', function () {
    $service = new StoreTimezoneService();

    // 2028 is a leap year; 2028-02-29 12:00:00 UTC is Feb 29 in UTC and store time
    $leapDate = $service->toStoreDate('2028-02-29T12:00:00Z', 'America/Los_Angeles');
    expect($leapDate)->toBe('2028-02-29');
});

it('handles daylight saving time transitions', function () {
    $service = new StoreTimezoneService();

    // US Spring Forward: 2026-03-08 07:00 UTC is 02:00 EST / 03:00 EDT in New York
    $springForward = $service->toStoreDate('2026-03-08T07:30:00Z', 'America/New_York');
    expect($springForward)->toBe('2026-03-08');

    // US Fall Back: 2026-11-01 06:30 UTC is 01:30 EST in New York
    $fallBack = $service->toStoreDate('2026-11-01T06:30:00Z', 'America/New_York');
    expect($fallBack)->toBe('2026-11-01');
});

it('gracefully falls back to UTC when timezone is invalid or empty', function () {
    $service = new StoreTimezoneService();

    $utcDate = '2026-06-15T14:00:00Z';
    expect($service->toStoreDate($utcDate, null))->toBe('2026-06-15');
    expect($service->toStoreDate($utcDate, ''))->toBe('2026-06-15');
    expect($service->toStoreDate($utcDate, 'Invalid/Unknown_Zone'))->toBe('2026-06-15');
});

it('accepts DateTimeInterface instances directly', function () {
    $service = new StoreTimezoneService();

    $carbon = CarbonImmutable::parse('2026-07-20T23:45:00Z');
    expect($service->toStoreDate($carbon, 'Europe/Paris'))->toBe('2026-07-21');
});

it('converts store-local date boundaries into UTC timestamps', function () {
    $service = new StoreTimezoneService();

    // For America/New_York (-04:00 in EDT on July 10),
    // 2026-07-10 00:00:00 NY is 2026-07-10 04:00:00 UTC.
    // 2026-07-10 23:59:59 NY is 2026-07-11 03:59:59 UTC.
    $utcStart = $service->startOfDayUtc('2026-07-10', 'America/New_York');
    $utcEnd = $service->endOfDayUtc('2026-07-10', 'America/New_York');

    expect($utcStart->toIso8601String())->toBe('2026-07-10T04:00:00+00:00');
    expect($utcEnd->toIso8601String())->toBe('2026-07-11T03:59:59+00:00');
});
