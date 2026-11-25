<?php

declare(strict_types=1);

use App\Analytics\Services\PeriodComparisonService;

it('calculates preceding period date range for an arbitrary N-day range', function () {
    $service = new PeriodComparisonService;

    // 2026-05-11 to 2026-05-20 is 10 days inclusive
    $current = ['from' => '2026-05-11', 'to' => '2026-05-20'];
    $previous = $service->calculatePreviousRange($current['from'], $current['to']);

    // Preceding 10 days inclusive is 2026-05-01 to 2026-05-10
    expect($previous['from'])->toBe('2026-05-01')
        ->and($previous['to'])->toBe('2026-05-10');
});

it('calculates monetary comparison with positive growth', function () {
    $service = new PeriodComparisonService;

    $current = '1500.000000';
    $previous = '1000.000000';

    $result = $service->compareMonetary($current, $previous);

    expect($result->delta)->toBe('500.000000')
        ->and($result->percentageChange)->toBe(50.0)
        ->and($result->direction)->toBe('increase');
});

it('calculates monetary comparison with negative growth', function () {
    $service = new PeriodComparisonService;

    $current = '800.000000';
    $previous = '1000.000000';

    $result = $service->compareMonetary($current, $previous);

    expect($result->delta)->toBe('-200.000000')
        ->and($result->percentageChange)->toBe(-20.0)
        ->and($result->direction)->toBe('decrease');
});

it('calculates comparison with zero change', function () {
    $service = new PeriodComparisonService;

    $current = '1000.000000';
    $previous = '1000.000000';

    $result = $service->compareMonetary($current, $previous);

    expect($result->delta)->toBe('0.000000')
        ->and($result->percentageChange)->toBe(0.0)
        ->and($result->direction)->toBe('unchanged');
});

it('safely handles zero previous period baseline without division by zero', function () {
    $service = new PeriodComparisonService;

    // When previous period is zero and current has sales
    $result = $service->compareMonetary('500.000000', '0.000000');

    expect($result->delta)->toBe('500.000000')
        ->and($result->percentageChange)->toBeNull()
        ->and($result->direction)->toBe('increase')
        ->and($result->isNew)->toBeTrue();

    // When both current and previous are zero
    $zeroResult = $service->compareMonetary('0.000000', '0.000000');

    expect($zeroResult->delta)->toBe('0.000000')
        ->and($zeroResult->percentageChange)->toBeNull()
        ->and($zeroResult->direction)->toBe('unchanged')
        ->and($zeroResult->isNew)->toBeFalse();
});

it('handles negative previous period baseline using absolute denominator', function () {
    $service = new PeriodComparisonService;

    // Previous net sales were negative (-100.00) due to refunds, current is 200.00
    // Delta: 200 - (-100) = 300.00
    // Percentage: (300 / |-100|) * 100 = 300%
    $result = $service->compareMonetary('200.000000', '-100.000000');

    expect($result->delta)->toBe('300.000000')
        ->and($result->percentageChange)->toBe(300.0)
        ->and($result->direction)->toBe('increase');
});

it('calculates integer metric comparisons such as units and orders', function () {
    $service = new PeriodComparisonService;

    $result = $service->compareCount(15, 10);
    expect($result->delta)->toBe(5)
        ->and($result->percentageChange)->toBe(50.0)
        ->and($result->direction)->toBe('increase');

    $decrease = $service->compareCount(5, 10);
    expect($decrease->delta)->toBe(-5)
        ->and($decrease->percentageChange)->toBe(-50.0)
        ->and($decrease->direction)->toBe('decrease');

    $zeroBase = $service->compareCount(10, 0);
    expect($zeroBase->delta)->toBe(10)
        ->and($zeroBase->percentageChange)->toBeNull()
        ->and($zeroBase->direction)->toBe('increase')
        ->and($zeroBase->isNew)->toBeTrue();
});
