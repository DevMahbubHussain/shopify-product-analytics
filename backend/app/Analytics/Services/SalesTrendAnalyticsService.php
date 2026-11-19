<?php

declare(strict_types=1);

namespace App\Analytics\Services;

use App\Analytics\Domain\FinancialMath;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SalesTrendAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function getSalesTrend(Shop $shop, string $from, string $to, string $interval = 'day'): array
    {
        $records = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('metric_date', '>=', $from)
            ->where('metric_date', '<=', $to)
            ->where('currency_code', $shop->currency_code)
            ->selectRaw('
                metric_date,
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(refunds), 0) as refunds,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(units_sold), 0) as units_sold,
                COALESCE(SUM(order_count), 0) as orders
            ')
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->keyBy(fn ($r): string => (string) $r->metric_date);

        $points = match ($interval) {
            'month' => $this->buildMonthPoints($records, $from, $to),
            'week' => $this->buildWeekPoints($records, $from, $to),
            default => $this->buildDayPoints($records, $from, $to),
        };

        return [
            'interval' => $interval,
            'currency' => $shop->currency_code,
            'points' => $points,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<string, object> $records
     * @return list<array<string, mixed>>
     */
    private function buildDayPoints($records, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $points = [];

        for ($cur = $start; $cur->lte($end); $cur = $cur->addDay()) {
            $dateStr = $cur->toDateString();
            $record = $records->get($dateStr);

            $points[] = [
                'date' => $dateStr,
                'gross_sales' => FinancialMath::formatDecimal((string) ($record?->gross_sales ?? '0')),
                'discounts' => FinancialMath::formatDecimal((string) ($record?->discounts ?? '0')),
                'refunds' => FinancialMath::formatDecimal((string) ($record?->refunds ?? '0')),
                'net_sales' => FinancialMath::formatDecimal((string) ($record?->net_sales ?? '0')),
                'units_sold' => (int) ($record?->units_sold ?? 0),
                'orders' => (int) ($record?->orders ?? 0),
            ];
        }

        return $points;
    }

    /**
     * @param \Illuminate\Support\Collection<string, object> $records
     * @return list<array<string, mixed>>
     */
    private function buildMonthPoints($records, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfMonth();
        $end = CarbonImmutable::parse($to)->startOfMonth();
        $buckets = [];

        for ($cur = $start; $cur->lte($end); $cur = $cur->addMonth()) {
            $monthKey = $cur->format('Y-m');
            $buckets[$monthKey] = [
                'gross_sales' => FinancialMath::ZERO,
                'discounts' => FinancialMath::ZERO,
                'refunds' => FinancialMath::ZERO,
                'net_sales' => FinancialMath::ZERO,
                'units_sold' => 0,
                'orders' => 0,
            ];
        }

        foreach ($records as $record) {
            $monthKey = substr((string) $record->metric_date, 0, 7);
            if (isset($buckets[$monthKey])) {
                $buckets[$monthKey]['gross_sales'] = FinancialMath::add(
                    $buckets[$monthKey]['gross_sales'],
                    FinancialMath::formatDecimal((string) $record->gross_sales)
                );
                $buckets[$monthKey]['discounts'] = FinancialMath::add(
                    $buckets[$monthKey]['discounts'],
                    FinancialMath::formatDecimal((string) $record->discounts)
                );
                $buckets[$monthKey]['refunds'] = FinancialMath::add(
                    $buckets[$monthKey]['refunds'],
                    FinancialMath::formatDecimal((string) $record->refunds)
                );
                $buckets[$monthKey]['net_sales'] = FinancialMath::add(
                    $buckets[$monthKey]['net_sales'],
                    FinancialMath::formatDecimal((string) $record->net_sales)
                );
                $buckets[$monthKey]['units_sold'] += (int) $record->units_sold;
                $buckets[$monthKey]['orders'] += (int) $record->orders;
            }
        }

        $points = [];
        foreach ($buckets as $monthKey => $vals) {
            $points[] = array_merge(['date' => $monthKey], $vals);
        }

        return $points;
    }

    /**
     * @param \Illuminate\Support\Collection<string, object> $records
     * @return list<array<string, mixed>>
     */
    private function buildWeekPoints($records, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfWeek();
        $end = CarbonImmutable::parse($to)->startOfWeek();
        $buckets = [];

        for ($cur = $start; $cur->lte($end); $cur = $cur->addWeek()) {
            $weekKey = $cur->toDateString();
            $buckets[$weekKey] = [
                'gross_sales' => FinancialMath::ZERO,
                'discounts' => FinancialMath::ZERO,
                'refunds' => FinancialMath::ZERO,
                'net_sales' => FinancialMath::ZERO,
                'units_sold' => 0,
                'orders' => 0,
            ];
        }

        foreach ($records as $record) {
            $weekKey = CarbonImmutable::parse((string) $record->metric_date)->startOfWeek()->toDateString();
            if (isset($buckets[$weekKey])) {
                $buckets[$weekKey]['gross_sales'] = FinancialMath::add(
                    $buckets[$weekKey]['gross_sales'],
                    FinancialMath::formatDecimal((string) $record->gross_sales)
                );
                $buckets[$weekKey]['discounts'] = FinancialMath::add(
                    $buckets[$weekKey]['discounts'],
                    FinancialMath::formatDecimal((string) $record->discounts)
                );
                $buckets[$weekKey]['refunds'] = FinancialMath::add(
                    $buckets[$weekKey]['refunds'],
                    FinancialMath::formatDecimal((string) $record->refunds)
                );
                $buckets[$weekKey]['net_sales'] = FinancialMath::add(
                    $buckets[$weekKey]['net_sales'],
                    FinancialMath::formatDecimal((string) $record->net_sales)
                );
                $buckets[$weekKey]['units_sold'] += (int) $record->units_sold;
                $buckets[$weekKey]['orders'] += (int) $record->orders;
            }
        }

        $points = [];
        foreach ($buckets as $weekKey => $vals) {
            $points[] = array_merge(['date' => $weekKey], $vals);
        }

        return $points;
    }
}
