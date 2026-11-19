<?php

declare(strict_types=1);

use App\Analytics\Domain\FinancialMath;

it('calculates gross sales using arbitrary precision multiplication and addition', function () {
    $lineItems = [
        ['quantity' => 2, 'unit_price' => '19.990000'],
        ['quantity' => 3, 'unit_price' => '5.500000'],
        ['quantity' => 1, 'unit_price' => '100.005000'],
    ];

    // (2 * 19.99) + (3 * 5.50) + (1 * 100.005) = 39.98 + 16.50 + 100.005 = 156.485000
    $grossSales = FinancialMath::grossSales($lineItems);

    expect($grossSales)->toBe('156.485000');
});

it('calculates net sales as gross minus discounts minus refunds', function () {
    $gross = '156.485000';
    $discounts = '15.000000';
    $refunds = '20.000000';

    // 156.485000 - 15.000000 - 20.000000 = 121.485000
    $netSales = FinancialMath::netSales($gross, $discounts, $refunds);

    expect($netSales)->toBe('121.485000');
});

it('supports negative net sales when refunds exceed gross minus discounts', function () {
    $gross = '50.000000';
    $discounts = '10.000000';
    $refunds = '100.000000';

    // 50 - 10 - 100 = -60.000000
    $netSales = FinancialMath::netSales($gross, $discounts, $refunds);

    expect($netSales)->toBe('-60.000000');
});

it('calculates units sold as purchased units minus refunded units', function () {
    $purchasedUnits = 15;
    $refundedUnits = 4;

    expect(FinancialMath::unitsSold($purchasedUnits, $refundedUnits))->toBe(11);
    expect(FinancialMath::unitsSold(2, 5))->toBe(-3);
    expect(FinancialMath::unitsSold(0, 0))->toBe(0);
});

it('calculates average order revenue with zero-order division safety', function () {
    $netSales = '150.000000';
    $orderCount = 3;

    // 150 / 3 = 50.000000
    expect(FinancialMath::averageOrderRevenue($netSales, $orderCount))->toBe('50.000000');

    // Division by zero returns '0.000000'
    expect(FinancialMath::averageOrderRevenue($netSales, 0))->toBe('0.000000');
    expect(FinancialMath::averageOrderRevenue('0.000000', 0))->toBe('0.000000');
});

it('adds multiple decimal monetary amounts without floating point precision loss', function () {
    // Classic IEEE 754 precision trap: 0.1 + 0.2 in float is 0.30000000000000004
    $sum = FinancialMath::add('0.100000', '0.200000');

    expect($sum)->toBe('0.300000');
});

it('subtracts decimal amounts correctly', function () {
    $result = FinancialMath::subtract('100.000000', '45.123456');

    expect($result)->toBe('54.876544');
});

it('normalizes arbitrary numeric string into standard 6-decimal representation', function () {
    expect(FinancialMath::formatDecimal('123'))->toBe('123.000000');
    expect(FinancialMath::formatDecimal('123.45'))->toBe('123.450000');
    expect(FinancialMath::formatDecimal('123.456789123'))->toBe('123.456789');
    expect(FinancialMath::formatDecimal('-45.5'))->toBe('-45.500000');
    expect(FinancialMath::formatDecimal('0'))->toBe('0.000000');
});
