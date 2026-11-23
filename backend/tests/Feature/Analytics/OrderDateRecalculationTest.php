<?php

declare(strict_types=1);

use App\Events\OrdersIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use App\Listeners\QueueMetricAggregationsFromOrders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('recalc-shop.myshopify.com', 'America/New_York');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1), productPayload(2)],
    ], 'recalc-shop.myshopify.com')->assertOk();
});

it('dispatches aggregation jobs for both old and new dates when order processed_at changes', function () {
    // 1. Ingest initial order on 2026-05-15
    $order1 = orderPayload(1, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T15:00:00Z',
        'line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 2, 'original_unit_price' => '25.00'])],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order1]], 'recalc-shop.myshopify.com')->assertOk();

    $productId = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/1')->value('id');

    // Run aggregation for 2026-05-15
    (new AggregateProductDailyMetricsJob($this->shopId, $productId, '2026-05-15'))->handle();

    $initialMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($initialMetric)->not->toBeNull()
        ->and($initialMetric->gross_sales)->toBe('50.000000')
        ->and($initialMetric->units_sold)->toBe(2);

    // 2. Fake queue for the job and ingest update changing processed_at to 2026-05-20
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $order1Updated = orderPayload(1, [
        'processed_at' => '2026-05-20T15:00:00Z',
        'shopify_updated_at' => '2026-05-20T16:00:00Z',
        'line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 2, 'original_unit_price' => '25.00'])],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order1Updated]], 'recalc-shop.myshopify.com')->assertOk();

    // MUST push aggregation job for the OLD date ('2026-05-15') to clear stale metrics
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($productId): bool {
        return $job->shopId === $this->shopId && $job->productId === $productId && $job->metricDate === '2026-05-15';
    });

    // MUST push aggregation job for the NEW date ('2026-05-20')
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($productId): bool {
        return $job->shopId === $this->shopId && $job->productId === $productId && $job->metricDate === '2026-05-20';
    });

    // 3. Execute both jobs and verify old date is cleared and new date has sales
    (new AggregateProductDailyMetricsJob($this->shopId, $productId, '2026-05-15'))->handle();
    (new AggregateProductDailyMetricsJob($this->shopId, $productId, '2026-05-20'))->handle();

    $oldMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    $newMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-20')
        ->first();

    expect($oldMetric->gross_sales)->toBe('0.000000')
        ->and($oldMetric->units_sold)->toBe(0)
        ->and($oldMetric->order_count)->toBe(0)
        ->and($newMetric->gross_sales)->toBe('50.000000')
        ->and($newMetric->units_sold)->toBe(2)
        ->and($newMetric->order_count)->toBe(1);
});

it('dispatches aggregation jobs for both old and new products when order line item product is swapped', function () {
    // 1. Initial order on 2026-05-15 with Product 1
    $order = orderPayload(1, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T15:00:00Z',
        'line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 1, 'original_unit_price' => '40.00'])],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], 'recalc-shop.myshopify.com')->assertOk();

    $prod1Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/1')->value('id');
    $prod2Id = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/2')->value('id');

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();

    expect(DB::table('product_daily_metrics')->where('product_id', $prod1Id)->where('metric_date', '2026-05-15')->value('gross_sales'))
        ->toBe('40.000000');

    // 2. Update order on same date, but swap Line Item to Product 2
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $orderSwapped = orderPayload(1, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T18:00:00Z',
        'line_items' => [lineItemPayload(1, 1, 2, ['quantity' => 1, 'original_unit_price' => '60.00'])],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderSwapped]], 'recalc-shop.myshopify.com')->assertOk();

    // MUST push aggregation job for OLD product (Prod 1) to clear stale metrics
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod1Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-15';
    });

    // MUST push aggregation job for NEW product (Prod 2)
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($prod2Id): bool {
        return $job->shopId === $this->shopId && $job->productId === $prod2Id && $job->metricDate === '2026-05-15';
    });

    (new AggregateProductDailyMetricsJob($this->shopId, $prod1Id, '2026-05-15'))->handle();
    (new AggregateProductDailyMetricsJob($this->shopId, $prod2Id, '2026-05-15'))->handle();

    expect(DB::table('product_daily_metrics')->where('product_id', $prod1Id)->where('metric_date', '2026-05-15')->value('gross_sales'))
        ->toBe('0.000000')
        ->and(DB::table('product_daily_metrics')->where('product_id', $prod2Id)->where('metric_date', '2026-05-15')->value('gross_sales'))
        ->toBe('60.000000');
});
