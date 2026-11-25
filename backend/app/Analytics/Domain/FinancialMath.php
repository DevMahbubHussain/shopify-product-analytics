<?php

declare(strict_types=1);

namespace App\Analytics\Domain;

final class FinancialMath
{
    public const int SCALE = 6;

    public const string ZERO = '0.000000';

    /**
     * @param  list<array{quantity: int, unit_price: string}>  $lineItems
     */
    public static function grossSales(array $lineItems): string
    {
        $total = self::ZERO;

        foreach ($lineItems as $item) {
            $lineSubtotal = bcmul((string) $item['quantity'], $item['unit_price'], self::SCALE);
            $total = bcadd($total, $lineSubtotal, self::SCALE);
        }

        return $total;
    }

    public static function netSales(string $gross, string $discounts, string $refunds): string
    {
        $afterDiscounts = bcsub($gross, $discounts, self::SCALE);

        return bcsub($afterDiscounts, $refunds, self::SCALE);
    }

    public static function unitsSold(int $purchasedUnits, int $refundedUnits): int
    {
        return $purchasedUnits - $refundedUnits;
    }

    public static function averageOrderRevenue(string $netSales, int $orderCount): string
    {
        if ($orderCount <= 0) {
            return self::ZERO;
        }

        return bcdiv($netSales, (string) $orderCount, self::SCALE);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd($left, $right, self::SCALE);
    }

    public static function subtract(string $left, string $right): string
    {
        return bcsub($left, $right, self::SCALE);
    }

    public static function formatDecimal(string|int|float $value): string
    {
        return bcadd((string) $value, '0', self::SCALE);
    }
}
