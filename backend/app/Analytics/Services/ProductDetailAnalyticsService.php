<?php

declare(strict_types=1);

namespace App\Analytics\Services;

use App\Analytics\Domain\FinancialMath;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ProductDetailAnalyticsService
{
    public function __construct(
        private PeriodComparisonService $periodComparisonService,
        private StoreTimezoneService $timezoneService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductAnalytics(Shop $shop, string $productId, string $from, string $to): array
    {
        $product = DB::table('products')
            ->where('id', $productId)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at')
            ->first(['id', 'shopify_product_gid', 'title', 'handle', 'vendor', 'product_type', 'status', 'image_url']);

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $previousRange = $this->periodComparisonService->calculatePreviousRange($from, $to);

        $currentMetrics = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('product_id', $productId)
            ->where('metric_date', '>=', $from)
            ->where('metric_date', '<=', $to)
            ->where('currency_code', $shop->currency_code)
            ->selectRaw('
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(refunds), 0) as refunds,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(units_sold), 0) as units_sold,
                COALESCE(SUM(order_count), 0) as orders
            ')
            ->first();

        $previousMetrics = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('product_id', $productId)
            ->where('metric_date', '>=', $previousRange['from'])
            ->where('metric_date', '<=', $previousRange['to'])
            ->where('currency_code', $shop->currency_code)
            ->selectRaw('
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(refunds), 0) as refunds,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(units_sold), 0) as units_sold,
                COALESCE(SUM(order_count), 0) as orders
            ')
            ->first();

        $currentGross = FinancialMath::formatDecimal((string) ($currentMetrics?->gross_sales ?? '0'));
        $currentDiscounts = FinancialMath::formatDecimal((string) ($currentMetrics?->discounts ?? '0'));
        $currentRefunds = FinancialMath::formatDecimal((string) ($currentMetrics?->refunds ?? '0'));
        $currentNet = FinancialMath::formatDecimal((string) ($currentMetrics?->net_sales ?? '0'));
        $currentUnits = (int) ($currentMetrics?->units_sold ?? 0);
        $currentOrders = (int) ($currentMetrics?->orders ?? 0);

        $prevGross = FinancialMath::formatDecimal((string) ($previousMetrics?->gross_sales ?? '0'));
        $prevNet = FinancialMath::formatDecimal((string) ($previousMetrics?->net_sales ?? '0'));
        $prevUnits = (int) ($previousMetrics?->units_sold ?? 0);
        $prevOrders = (int) ($previousMetrics?->orders ?? 0);

        $averageOrderRevenue = FinancialMath::averageOrderRevenue($currentNet, $currentOrders);

        $grossComp = $this->periodComparisonService->compareMonetary($currentGross, $prevGross);
        $netComp = $this->periodComparisonService->compareMonetary($currentNet, $prevNet);
        $unitsComp = $this->periodComparisonService->compareCount($currentUnits, $prevUnits);
        $ordersComp = $this->periodComparisonService->compareCount($currentOrders, $prevOrders);

        return [
            'product' => [
                'id' => (string) $product->id,
                'shopify_product_gid' => (string) $product->shopify_product_gid,
                'title' => (string) $product->title,
                'handle' => (string) $product->handle,
                'vendor' => $product->vendor,
                'product_type' => $product->product_type,
                'status' => (string) $product->status,
                'image_url' => $product->image_url,
            ],
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
                    'delta' => $grossComp->delta,
                    'percentage_change' => $grossComp->percentageChange,
                    'direction' => $grossComp->direction,
                ],
                'net_sales' => [
                    'delta' => $netComp->delta,
                    'percentage_change' => $netComp->percentageChange,
                    'direction' => $netComp->direction,
                ],
                'units_sold' => [
                    'delta' => $unitsComp->delta,
                    'percentage_change' => $unitsComp->percentageChange,
                    'direction' => $unitsComp->direction,
                ],
                'orders' => [
                    'delta' => $ordersComp->delta,
                    'percentage_change' => $ordersComp->percentageChange,
                    'direction' => $ordersComp->direction,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getVariantAnalytics(Shop $shop, string $productId, string $from, string $to): array
    {
        $product = DB::table('products')
            ->where('id', $productId)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at')
            ->first(['id', 'title']);

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $variants = DB::table('product_variants')
            ->where('shop_id', $shop->id)
            ->where('product_id', $productId)
            ->get(['id', 'shopify_variant_gid', 'title', 'sku', 'price']);

        $startUtc = $this->timezoneService->startOfDayUtc($from, $shop->timezone);
        $endUtc = $this->timezoneService->endOfDayUtc($to, $shop->timezone);

        $orderLines = DB::table('order_line_items')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'order_line_items.order_id')
                    ->on('orders.shop_id', '=', 'order_line_items.shop_id');
            })
            ->where('order_line_items.shop_id', $shop->id)
            ->where('order_line_items.product_id', $productId)
            ->where('orders.processed_at', '>=', $startUtc)
            ->where('orders.processed_at', '<=', $endUtc)
            ->select([
                'order_line_items.variant_id',
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
            ->where('refund_line_items.shop_id', $shop->id)
            ->where('order_line_items.product_id', $productId)
            ->where('refund_line_items.processed_at', '>=', $startUtc)
            ->where('refund_line_items.processed_at', '<=', $endUtc)
            ->select([
                'order_line_items.variant_id',
                'refund_line_items.refunded_quantity',
                'refund_line_items.refunded_subtotal',
            ])
            ->get();

        $variantRows = [];

        foreach ($variants as $variant) {
            $variantId = (string) $variant->id;
            $varLines = $orderLines->where('variant_id', $variantId);
            $varRefunds = $refundLines->where('variant_id', $variantId);

            $purchasedUnits = 0;
            $grossSales = FinancialMath::ZERO;
            $discounts = FinancialMath::ZERO;
            $orderIds = [];

            foreach ($varLines as $line) {
                $purchasedUnits += (int) $line->quantity;
                $lineGross = bcmul((string) $line->quantity, (string) $line->original_unit_price, FinancialMath::SCALE);
                $grossSales = bcadd($grossSales, $lineGross, FinancialMath::SCALE);
                $discounts = bcadd($discounts, (string) $line->discount_amount, FinancialMath::SCALE);
                $orderIds[$line->order_id] = true;
            }

            $refundedUnits = 0;
            $refundsTotal = FinancialMath::ZERO;

            foreach ($varRefunds as $refund) {
                $refundedUnits += (int) $refund->refunded_quantity;
                $refundsTotal = bcadd($refundsTotal, (string) $refund->refunded_subtotal, FinancialMath::SCALE);
            }

            $netSales = FinancialMath::netSales($grossSales, $discounts, $refundsTotal);
            $unitsSold = FinancialMath::unitsSold($purchasedUnits, $refundedUnits);

            $variantRows[] = [
                'id' => $variantId,
                'shopify_variant_gid' => (string) $variant->shopify_variant_gid,
                'title' => (string) $variant->title,
                'sku' => $variant->sku,
                'price' => FinancialMath::formatDecimal((string) $variant->price),
                'gross_sales' => $grossSales,
                'discounts' => $discounts,
                'refunds' => $refundsTotal,
                'net_sales' => $netSales,
                'units_sold' => $unitsSold,
                'orders' => count($orderIds),
            ];
        }

        usort($variantRows, function ($a, $b): int {
            return bccomp((string) $b['net_sales'], (string) $a['net_sales'], FinancialMath::SCALE);
        });

        return [
            'product_id' => $productId,
            'date_range' => [
                'from' => $from,
                'to' => $to,
            ],
            'currency' => $shop->currency_code,
            'variants' => $variantRows,
        ];
    }
}
