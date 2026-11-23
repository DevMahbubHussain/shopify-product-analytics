<?php

declare(strict_types=1);

use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('refund-recalc.myshopify.com', 'America/New_York');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1), productPayload(2)],
    ], 'refund-recalc.myshopify.com')->assertOk();

    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'processed_at' => '2026-05-10T12:00:00Z',
                'line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 2, 'original_unit_price' => '50.00'])],
            ]),
            orderPayload(2, [
                'processed_at' => '2026-05-10T12:00:00Z',
                'line_items' => [lineItemPayload(2, 1, 2, ['quantity' => 2, 'original_unit_price' => '60.00'])],
            ]),
        ],
    ], 'refund-recalc.myshopify.com')->assertOk();
});

it('dispatches aggregation jobs for both old and new dates when refund processed_at changes', function () {
    $prod1Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/1')->value('id');

    // 1. Initial refund on 2026-05-15: 1 unit refunded at $50
    $refundLine = refundLinePayload(1, 1, [
        'quantity' => 1,
        'subtotal' => '50.00',
        'processed_at' => '2026-05-15T15:00:00Z',
    ]);

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundLine]], 'refund-recalc.myshopify.com')->assertOk();

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();

    $initialMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $prod1Id)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($initialMetric)->not->toBeNull()
        ->and($initialMetric->refunds)->toBe('50.000000')
        ->and($initialMetric->net_sales)->toBe('-50.000000')
        ->and($initialMetric->units_sold)->toBe(-1);

    // 2. Fake queue and update refund processed_at to 2026-05-20
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $refundLineUpdated = refundLinePayload(1, 1, [
        'quantity' => 1,
        'subtotal' => '50.00',
        'processed_at' => '2026-05-20T15:00:00Z',
    ]);

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundLineUpdated]], 'refund-recalc.myshopify.com')->assertOk();

    // MUST push aggregation job for the OLD refund date ('2026-05-15')
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod1Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-15';
    });

    // MUST push aggregation job for the NEW refund date ('2026-05-20')
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod1Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-20';
    });

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();
    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-20'))->handle();

    $oldMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $prod1Id)
        ->where('metric_date', '2026-05-15')
        ->first();

    $newMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $prod1Id)
        ->where('metric_date', '2026-05-20')
        ->first();

    expect($oldMetric->refunds)->toBe('0.000000')
        ->and($oldMetric->net_sales)->toBe('0.000000')
        ->and($oldMetric->units_sold)->toBe(0)
        ->and($newMetric->refunds)->toBe('50.000000')
        ->and($newMetric->net_sales)->toBe('-50.000000')
        ->and($newMetric->units_sold)->toBe(-1);
});

it('dispatches aggregation jobs for both old and new products when refund line item is reassociated', function () {
    $prod1Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/1')->value('id');
    $prod2Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/2')->value('id');

    // 1. Initial refund on 2026-05-15 for Order 1 / LineItem 1 (Product 1)
    $refundLine = refundLinePayload(1, 1, [
        'quantity' => 1,
        'subtotal' => '50.00',
        'processed_at' => '2026-05-15T15:00:00Z',
    ]);

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundLine]], 'refund-recalc.myshopify.com')->assertOk();

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();

    expect(DB::table('product_daily_metrics')->where('product_id', $prod1Id)->where('metric_date', '2026-05-15')->value('refunds'))
        ->toBe('50.000000');

    // 2. Update refund to associate with Order 2 / LineItem 2 (Product 2)
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $refundLineReassociated = [
        'refund_gid' => 'gid://shopify/Refund/1',
        'refund_line_item_gid' => 'gid://shopify/RefundLineItem/1',
        'order_gid' => 'gid://shopify/Order/2',
        'line_item_gid' => 'gid://shopify/LineItem/21',
        'quantity' => 1,
        'subtotal' => '60.00',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-15T15:00:00Z',
    ];

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundLineReassociated]], 'refund-recalc.myshopify.com')->assertOk();

    // MUST push aggregation job for OLD product (Prod 1)
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod1Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-15';
    });

    // MUST push aggregation job for NEW product (Prod 2)
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod2Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod2Id && $job->metricDate === '2026-05-15';
    });

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();
    (new AggregateProductDailyMetricsJob($this->shopId, $prod2Id, '2026-05-15'))->handle();

    expect(DB::table('product_daily_metrics')->where('product_id', $prod1Id)->where('metric_date', '2026-05-15')->value('refunds'))
        ->toBe('0.000000')
        ->and(DB::table('product_daily_metrics')->where('product_id', $prod2Id)->where('metric_date', '2026-05-15')->value('refunds'))
        ->toBe('60.000000');
});
