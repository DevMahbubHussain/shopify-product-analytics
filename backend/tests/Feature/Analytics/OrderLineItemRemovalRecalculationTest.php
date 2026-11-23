<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('remove-line-shop.myshopify.com', 'America/New_York');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1), productPayload(2)],
    ], 'remove-line-shop.myshopify.com')->assertOk();
});

it('deletes removed line items and recalculates metrics when a line item is removed from an order', function (): void {
    // 1. Initial order on 2026-05-15 with both Product 1 and Product 2
    $order = orderPayload(1, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T15:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, ['quantity' => 1, 'original_unit_price' => '40.00', 'discount_amount' => '0.00']),
            lineItemPayload(1, 2, 2, ['quantity' => 1, 'original_unit_price' => '60.00', 'discount_amount' => '0.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], 'remove-line-shop.myshopify.com')->assertOk();

    $prod1Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/1')->value('id');
    $prod2Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/2')->value('id');

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();
    (new AggregateProductDailyMetricsJob($this->shopId, $prod2Id, '2026-05-15'))->handle();

    expect(DB::table('product_daily_metrics')->where('product_id', $prod1Id)->where('metric_date', '2026-05-15')->value('gross_sales'))
        ->toBe('40.000000')
        ->and(DB::table('product_daily_metrics')->where('product_id', $prod2Id)->where('metric_date', '2026-05-15')->value('gross_sales'))
        ->toBe('60.000000');

    // 2. Update order on same date, removing Line Item 2 (Product 2)
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $orderUpdated = orderPayload(1, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T18:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, ['quantity' => 1, 'original_unit_price' => '40.00', 'discount_amount' => '0.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderUpdated]], 'remove-line-shop.myshopify.com')->assertOk();

    // Line item 2 (gid://shopify/LineItem/12) MUST be deleted from database table order_line_items
    $storedLineGids = DB::table('order_line_items')
        ->where('shop_id', $this->shopId)
        ->pluck('shopify_line_item_gid')
        ->all();

    expect($storedLineGids)->toContain('gid://shopify/LineItem/11')
        ->and($storedLineGids)->not->toContain('gid://shopify/LineItem/12');

    // Aggregation job MUST be dispatched for both Product 2 (to clear stale metric) and Product 1
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod2Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod2Id && $job->metricDate === '2026-05-15';
    });

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod1Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-15';
    });

    // 3. Execute aggregation jobs and verify Product 2's daily metric is completely cleared
    (new AggregateProductDailyMetricsJob($this->shopId, $prod2Id, '2026-05-15'))->handle();
    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();

    $prod2Metric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $prod2Id)
        ->where('metric_date', '2026-05-15')
        ->first();

    $prod1Metric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $prod1Id)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($prod2Metric->gross_sales)->toBe('0.000000')
        ->and($prod2Metric->net_sales)->toBe('0.000000')
        ->and($prod2Metric->units_sold)->toBe(0)
        ->and($prod2Metric->order_count)->toBe(0)
        ->and($prod1Metric->gross_sales)->toBe('40.000000')
        ->and($prod1Metric->net_sales)->toBe('40.000000')
        ->and($prod1Metric->units_sold)->toBe(1)
        ->and($prod1Metric->order_count)->toBe(1);
});
