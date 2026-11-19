<?php

declare(strict_types=1);

namespace App\Analytics\Services;

use App\Analytics\Domain\FinancialMath;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

final readonly class OverviewAnalyticsService
{
    public function __construct(
        private PeriodComparisonService $periodComparisonService,
        private StoreTimezoneService $timezoneService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(Shop $shop, string $from, string $to): array
    {
        $previousRange = $this->periodComparisonService->calculatePreviousRange($from, $to);

        $currentMetrics = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('metric_date', '>=', $from)
            ->where('metric_date', '<=', $to)
            ->where('currency_code', $shop->currency_code)
            ->selectRaw(
                '
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(refunds), 0) as refunds,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(units_sold), 0) as units_sold
            '
            )
            ->first();

        $previousMetrics = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('metric_date', '>=', $previousRange['from'])
            ->where('metric_date', '<=', $previousRange['to'])
            ->where('currency_code', $shop->currency_code)
            ->selectRaw(
                '
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(refunds), 0) as refunds,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(units_sold), 0) as units_sold
            '
            )
            ->first();

        $currentStartUtc = $this->timezoneService->startOfDayUtc($from, $shop->timezone);
        $currentEndUtc = $this->timezoneService->endOfDayUtc($to, $shop->timezone);
        $currentOrders = DB::table('orders')
            ->where('shop_id', $shop->id)
            ->where('processed_at', '>=', $currentStartUtc)
            ->where('processed_at', '<=', $currentEndUtc)
            ->count();

        $prevStartUtc = $this->timezoneService->startOfDayUtc($previousRange['from'], $shop->timezone);
        $prevEndUtc = $this->timezoneService->endOfDayUtc($previousRange['to'], $shop->timezone);
        $prevOrders = DB::table('orders')
            ->where('shop_id', $shop->id)
            ->where('processed_at', '>=', $prevStartUtc)
            ->where('processed_at', '<=', $prevEndUtc)
            ->count();

        $currentGross = FinancialMath::formatDecimal((string) ($currentMetrics?->gross_sales ?? '0'));
        $currentDiscounts = FinancialMath::formatDecimal((string) ($currentMetrics?->discounts ?? '0'));
        $currentRefunds = FinancialMath::formatDecimal((string) ($currentMetrics?->refunds ?? '0'));
        $currentNet = FinancialMath::formatDecimal((string) ($currentMetrics?->net_sales ?? '0'));
        $currentUnits = (int) ($currentMetrics?->units_sold ?? 0);

        $prevGross = FinancialMath::formatDecimal((string) ($previousMetrics?->gross_sales ?? '0'));
        $prevNet = FinancialMath::formatDecimal((string) ($previousMetrics?->net_sales ?? '0'));
        $prevUnits = (int) ($previousMetrics?->units_sold ?? 0);

        $averageOrderRevenue = FinancialMath::averageOrderRevenue($currentNet, $currentOrders);

        $grossComparison = $this->periodComparisonService->compareMonetary($currentGross, $prevGross);
        $netComparison = $this->periodComparisonService->compareMonetary($currentNet, $prevNet);
        $unitsComparison = $this->periodComparisonService->compareCount($currentUnits, $prevUnits);
        $ordersComparison = $this->periodComparisonService->compareCount($currentOrders, $prevOrders);

        return [
            'date_range' => [
                'from' => $from,
                'to' => $to,
            ],
            'previous_date_range' => [
                'from' => $previousRange['from'],
                'to' => $previousRange['to'],
            ],
            'currency' => $shop->currency_code,
            'summary' => [
                'gross_sales' => $currentGross,
                'discounts' => $currentDiscounts,
                'refunds' => $currentRefunds,
                'net_sales' => $currentNet,
                'units_sold' => $currentUnits,
                'orders' => $currentOrders,
                'average_order_revenue' => $averageOrderRevenue,
            ],
            'comparison' => [
                'gross_sales' => [
                    'delta' => $grossComparison->delta,
                    'percentage_change' => $grossComparison->percentageChange,
                    'direction' => $grossComparison->direction,
                ],
                'net_sales' => [
                    'delta' => $netComparison->delta,
                    'percentage_change' => $netComparison->percentageChange,
                    'direction' => $netComparison->direction,
                ],
                'units_sold' => [
                    'delta' => $unitsComparison->delta,
                    'percentage_change' => $unitsComparison->percentageChange,
                    'direction' => $unitsComparison->direction,
                ],
                'orders' => [
                    'delta' => $ordersComparison->delta,
                    'percentage_change' => $ordersComparison->percentageChange,
                    'direction' => $ordersComparison->direction,
                ],
            ],
        ];
    }
}
