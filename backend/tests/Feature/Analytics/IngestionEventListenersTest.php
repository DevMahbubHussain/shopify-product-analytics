<?php

declare(strict_types=1);

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use App\Listeners\QueueMetricAggregationsFromOrders;
use App\Listeners\QueueMetricAggregationsFromRefunds;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('QueueMetricAggregationsFromOrders implements ShouldQueue and dispatches aggregation jobs', function () {
    Queue::fake();

    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'listener-orders.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York', // UTC-4 in May
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $prod1Id = (string) Str::uuid();
    $prod2Id = (string) Str::uuid();
    DB::table('products')->insert([
        ['id' => $prod1Id, 'shop_id' => $shopId, 'shopify_product_gid' => 'gid://shopify/Product/111', 'title' => 'P1', 'handle' => 'p1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $prod2Id, 'shop_id' => $shopId, 'shopify_product_gid' => 'gid://shopify/Product/222', 'title' => 'P2', 'handle' => 'p2', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Order 1: 2026-05-15 14:00 EDT (18:00 UTC) -> Date 2026-05-15
    // Has 2 line items for Prod 1, and 1 line item for Prod 2
    $order1Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order1Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/333',
        'order_name' => '#333',
        'currency_code' => 'USD',
        'processed_at' => CarbonImmutable::parse('2026-05-15T18:00:00Z'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        ['id' => (string) Str::uuid(), 'shop_id' => $shopId, 'order_id' => $order1Id, 'product_id' => $prod1Id, 'shopify_line_item_gid' => 'gid://shopify/LineItem/101', 'title_snapshot' => 'P1 item A', 'quantity' => 1, 'original_unit_price' => '10.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'shop_id' => $shopId, 'order_id' => $order1Id, 'product_id' => $prod1Id, 'shopify_line_item_gid' => 'gid://shopify/LineItem/102', 'title_snapshot' => 'P1 item B', 'quantity' => 2, 'original_unit_price' => '10.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'shop_id' => $shopId, 'order_id' => $order1Id, 'product_id' => $prod2Id, 'shopify_line_item_gid' => 'gid://shopify/LineItem/103', 'title_snapshot' => 'P2 item', 'quantity' => 1, 'original_unit_price' => '20.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
        // Custom item with no product_id
        ['id' => (string) Str::uuid(), 'shop_id' => $shopId, 'order_id' => $order1Id, 'product_id' => null, 'shopify_line_item_gid' => 'gid://shopify/LineItem/104', 'title_snapshot' => 'Custom Gift Wrap', 'quantity' => 1, 'original_unit_price' => '5.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Order 2: 2026-05-16 01:00 EDT (05:00 UTC) -> Date 2026-05-16
    // Has line item for Prod 1
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order2Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/444',
        'order_name' => '#444',
        'currency_code' => 'USD',
        'processed_at' => CarbonImmutable::parse('2026-05-16T05:00:00Z'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        ['id' => (string) Str::uuid(), 'shop_id' => $shopId, 'order_id' => $order2Id, 'product_id' => $prod1Id, 'shopify_line_item_gid' => 'gid://shopify/LineItem/105', 'title_snapshot' => 'P1 item', 'quantity' => 1, 'original_unit_price' => '10.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $listener = app(QueueMetricAggregationsFromOrders::class);
    expect($listener)->toBeInstanceOf(ShouldQueue::class);

    $event = new OrdersIngested($shopId, [$order1Id, $order2Id]);
    $listener->handle($event);

    // Expected dispatches:
    // 1. (shopId, prod1Id, '2026-05-15')
    // 2. (shopId, prod2Id, '2026-05-15')
    // 3. (shopId, prod1Id, '2026-05-16')
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, 3);

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($shopId, $prod1Id): bool {
        return $job->shopId === $shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-15';
    });

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($shopId, $prod2Id): bool {
        return $job->shopId === $shopId && $job->productId === $prod2Id && $job->metricDate === '2026-05-15';
    });

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($shopId, $prod1Id): bool {
        return $job->shopId === $shopId && $job->productId === $prod1Id && $job->metricDate === '2026-05-16';
    });
});

it('QueueMetricAggregationsFromRefunds implements ShouldQueue and dispatches aggregation jobs', function () {
    Queue::fake();

    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'listener-refunds.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/555',
        'title' => 'Sneakers',
        'handle' => 'sneakers',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/666',
        'order_name' => '#666',
        'currency_code' => 'USD',
        'processed_at' => CarbonImmutable::parse('2026-05-10T12:00:00Z'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lineId = (string) Str::uuid();
    DB::table('order_line_items')->insert([
        'id' => $lineId,
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/777',
        'title_snapshot' => 'Sneakers',
        'quantity' => 1,
        'original_unit_price' => '90.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Refund processed at 2026-05-20 18:00 UTC -> 2026-05-20 EDT
    DB::table('refund_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'line_item_id' => $lineId,
        'shopify_refund_gid' => 'gid://shopify/Refund/888',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/999',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '90.000000',
        'currency_code' => 'USD',
        'processed_at' => CarbonImmutable::parse('2026-05-20T18:00:00Z'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $listener = app(QueueMetricAggregationsFromRefunds::class);
    expect($listener)->toBeInstanceOf(ShouldQueue::class);

    $event = new RefundsIngested($shopId, [$orderId]);
    $listener->handle($event);

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, 1);
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($shopId, $productId): bool {
        return $job->shopId === $shopId && $job->productId === $productId && $job->metricDate === '2026-05-20';
    });
});
