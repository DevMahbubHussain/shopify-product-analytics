<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Analytics\Domain\FinancialMath;
use App\Analytics\Services\StoreTimezoneService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AggregateProductDailyMetricsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $shopId,
        public readonly string $productId,
        public readonly string $metricDate
    ) {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function uniqueId(): string
    {
        return "{$this->shopId}:{$this->productId}:{$this->metricDate}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("metrics:{$this->shopId}:{$this->productId}:{$this->metricDate}"))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    public function handle(?StoreTimezoneService $timezoneService = null): void
    {
        $timezoneService ??= app(StoreTimezoneService::class);
        $shop = DB::table('shops')->where('id', $this->shopId)->first(['id', 'timezone', 'currency_code']);
        if ($shop === null) {
            Log::warning('AggregateProductDailyMetricsJob: shop not found', [
                'shop_id' => $this->shopId,
                'product_id' => $this->productId,
                'metric_date' => $this->metricDate,
            ]);

            return;
        }

        $startUtc = $timezoneService->startOfDayUtc($this->metricDate, $shop->timezone);
        $nextDayUtc = $timezoneService->startOfNextDayUtc($this->metricDate, $shop->timezone);

        $orderLines = DB::table('order_line_items')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'order_line_items.order_id')
                    ->on('orders.shop_id', '=', 'order_line_items.shop_id');
            })
            ->where('order_line_items.shop_id', $this->shopId)
            ->where('order_line_items.product_id', $this->productId)
            ->where('orders.processed_at', '>=', $startUtc)
            ->where('orders.processed_at', '<', $nextDayUtc)
            ->select([
                'order_line_items.currency_code',
                'order_line_items.quantity',
                'order_line_items.original_unit_price',
                'order_line_items.discount_amount',
                'order_line_items.order_id',
            ])
            ->get();

        $refundLines = DB::table('refund_line_items')
            ->join('order_line_items', function ($join): void {
                $join->on('order_line_items.id', '=', 'refund_line_items.line_item_id')
                    ->on('order_line_items.shop_id', '=', 'refund_line_items.shop_id');
            })
            ->where('refund_line_items.shop_id', $this->shopId)
            ->where('order_line_items.product_id', $this->productId)
            ->where('refund_line_items.processed_at', '>=', $startUtc)
            ->where('refund_line_items.processed_at', '<', $nextDayUtc)
            ->select([
                'refund_line_items.currency_code',
                'refund_line_items.refunded_quantity',
                'refund_line_items.refunded_subtotal',
            ])
            ->get();

        $historicalCurrencies = DB::table('product_daily_metrics')
            ->where('shop_id', $this->shopId)
            ->where('product_id', $this->productId)
            ->where('metric_date', $this->metricDate)
            ->pluck('currency_code');

        $currencies = $orderLines->pluck('currency_code')
            ->merge($refundLines->pluck('currency_code'))
            ->merge($historicalCurrencies)
            ->unique()
            ->values();

        if ($currencies->isEmpty()) {
            $currencies = collect([$shop->currency_code]);
        }

        foreach ($currencies as $currency) {
            $currencyOrderLines = $orderLines->where('currency_code', $currency);
            $currencyRefundLines = $refundLines->where('currency_code', $currency);

            $purchasedUnits = 0;
            $grossSales = FinancialMath::ZERO;
            $discounts = FinancialMath::ZERO;
            $orderIds = [];

            foreach ($currencyOrderLines as $line) {
                $purchasedUnits += (int) $line->quantity;
                $lineGross = bcmul((string) $line->quantity, (string) $line->original_unit_price, FinancialMath::SCALE);
                $grossSales = bcadd($grossSales, $lineGross, FinancialMath::SCALE);
                $discounts = bcadd($discounts, (string) $line->discount_amount, FinancialMath::SCALE);
                $orderIds[$line->order_id] = true;
            }

            $refundedUnits = 0;
            $refunds = FinancialMath::ZERO;

            foreach ($currencyRefundLines as $refund) {
                $refundedUnits += (int) $refund->refunded_quantity;
                $refunds = bcadd($refunds, (string) $refund->refunded_subtotal, FinancialMath::SCALE);
            }

            $netSales = FinancialMath::netSales($grossSales, $discounts, $refunds);
            $unitsSold = FinancialMath::unitsSold($purchasedUnits, $refundedUnits);
            $orderCount = count($orderIds);

            DB::transaction(function () use ($currency, $grossSales, $discounts, $refunds, $netSales, $unitsSold, $orderCount): void {
                $now = CarbonImmutable::now();
                DB::table('product_daily_metrics')->upsert(
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $this->shopId,
                        'product_id' => $this->productId,
                        'metric_date' => $this->metricDate,
                        'currency_code' => $currency,
                        'gross_sales' => $grossSales,
                        'discounts' => $discounts,
                        'refunds' => $refunds,
                        'net_sales' => $netSales,
                        'units_sold' => $unitsSold,
                        'order_count' => $orderCount,
                        'calculated_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['shop_id', 'product_id', 'metric_date', 'currency_code'],
                    [
                        'gross_sales',
                        'discounts',
                        'refunds',
                        'net_sales',
                        'units_sold',
                        'order_count',
                        'calculated_at',
                        'updated_at',
                    ]
                );
            }, 5);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('AggregateProductDailyMetricsJob failed permanently', [
            'shop_id' => $this->shopId,
            'product_id' => $this->productId,
            'metric_date' => $this->metricDate,
            'error' => $exception->getMessage(),
        ]);
    }
}
