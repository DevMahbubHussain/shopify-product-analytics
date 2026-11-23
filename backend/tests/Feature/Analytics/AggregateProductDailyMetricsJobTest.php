<?php

declare(strict_types=1);

use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('implements ShouldQueue, ShouldBeUniqueUntilProcessing, and specifies WithoutOverlapping middleware', function () {
    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("{$shopId}:{$productId}:{$metricDate}");

    $middleware = $job->middleware();
    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);

    /** @var WithoutOverlapping $withoutOverlapping */
    $withoutOverlapping = $middleware[0];
    expect($withoutOverlapping->expiresAfter)->toBe(120)
        ->and($withoutOverlapping->releaseAfter)->toBe(10);
});

it('aggregates orders and refunds for a product on a store metric date', function () {
    $shopId = (string) Str::uuid();
    $currency = 'USD';
    $timezone = 'America/New_York'; // UTC-4 during EDT in May

    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'test-shop.myshopify.com',
        'currency_code' => $currency,
        'timezone' => $timezone,
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/1001',
        'title' => 'Vintage T-Shirt',
        'handle' => 'vintage-t-shirt',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = (string) Str::uuid();
    DB::table('product_variants')->insert([
        'id' => $variantId,
        'shop_id' => $shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => 'gid://shopify/ProductVariant/2001',
        'title' => 'Medium / Black',
        'price' => '25.000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Metric Date: 2026-05-15
    // In America/New_York (EDT, UTC-4), 2026-05-15 12:00 EDT is 2026-05-15 16:00 UTC
    $order1ProcessedAt = CarbonImmutable::parse('2026-05-15T16:00:00Z');
    $order1Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order1Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/3001',
        'order_name' => '#1001',
        'currency_code' => $currency,
        'processed_at' => $order1ProcessedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $line1Id = (string) Str::uuid();
    DB::table('order_line_items')->insert([
        'id' => $line1Id,
        'shop_id' => $shopId,
        'order_id' => $order1Id,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/4001',
        'title_snapshot' => 'Vintage T-Shirt',
        'quantity' => 2,
        'original_unit_price' => '25.000000',
        'discount_amount' => '5.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Order 2 on same day: 1 item at 25.00, 0 discount
    $order2ProcessedAt = CarbonImmutable::parse('2026-05-15T20:00:00Z');
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order2Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/3002',
        'order_name' => '#1002',
        'currency_code' => $currency,
        'processed_at' => $order2ProcessedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $line2Id = (string) Str::uuid();
    DB::table('order_line_items')->insert([
        'id' => $line2Id,
        'shop_id' => $shopId,
        'order_id' => $order2Id,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/4002',
        'title_snapshot' => 'Vintage T-Shirt',
        'quantity' => 1,
        'original_unit_price' => '25.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Refund on Order 1 on the same day: 1 unit refunded at 20.00
    $refundProcessedAt = CarbonImmutable::parse('2026-05-15T22:00:00Z');
    DB::table('refund_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $order1Id,
        'line_item_id' => $line1Id,
        'shopify_refund_gid' => 'gid://shopify/Refund/5001',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/6001',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '20.000000',
        'currency_code' => $currency,
        'processed_at' => $refundProcessedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Dispatch and execute the job
    $job = new AggregateProductDailyMetricsJob($shopId, $productId, '2026-05-15');
    $job->handle();

    // Verify row in product_daily_metrics
    // Gross: (2 * 25.00) + (1 * 25.00) = 75.000000
    // Discounts: 5.000000
    // Refunds: 20.000000
    // Net: 75.000000 - 5.000000 - 20.000000 = 50.000000
    // Units: 3 purchased - 1 refunded = 2
    // Orders: 2 distinct orders
    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->gross_sales)->toBe('75.000000')
        ->and($metric->discounts)->toBe('5.000000')
        ->and($metric->refunds)->toBe('20.000000')
        ->and($metric->net_sales)->toBe('50.000000')
        ->and($metric->units_sold)->toBe(2)
        ->and($metric->order_count)->toBe(2)
        ->and($metric->calculated_at)->not->toBeNull();
});

it('is idempotent on repeated execution', function () {
    $shopId = (string) Str::uuid();
    $currency = 'USD';

    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'idempotent-shop.myshopify.com',
        'currency_code' => $currency,
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/1002',
        'title' => 'Running Cap',
        'handle' => 'running-cap',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/3003',
        'order_name' => '#1003',
        'currency_code' => $currency,
        'processed_at' => '2026-05-15T12:00:00Z',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/4003',
        'title_snapshot' => 'Running Cap',
        'quantity' => 1,
        'original_unit_price' => '30.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, '2026-05-15');

    // Run first time
    $job->handle();
    // Run second time
    $job->handle();

    // Must still have exactly 1 row with unchanged metric numbers
    $metrics = DB::table('product_daily_metrics')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->get();

    expect($metrics)->toHaveCount(1)
        ->and($metrics->first()->gross_sales)->toBe('30.000000')
        ->and($metrics->first()->net_sales)->toBe('30.000000')
        ->and($metrics->first()->units_sold)->toBe(1);
});

it('handles days with refunds only resulting in negative net sales and units', function () {
    $shopId = (string) Str::uuid();
    $currency = 'USD';

    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'refund-only-shop.myshopify.com',
        'currency_code' => $currency,
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/1003',
        'title' => 'Wool Sweater',
        'handle' => 'wool-sweater',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Order placed on prior date (e.g. 2026-05-10)
    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/3004',
        'order_name' => '#1004',
        'currency_code' => $currency,
        'processed_at' => '2026-05-10T12:00:00Z',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lineId = (string) Str::uuid();
    DB::table('order_line_items')->insert([
        'id' => $lineId,
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/4004',
        'title_snapshot' => 'Wool Sweater',
        'quantity' => 2,
        'original_unit_price' => '80.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Refund processed on 2026-05-15: 1 unit refunded at 80.00
    DB::table('refund_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'line_item_id' => $lineId,
        'shopify_refund_gid' => 'gid://shopify/Refund/5002',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/6002',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '80.000000',
        'currency_code' => $currency,
        'processed_at' => '2026-05-15T14:00:00Z',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, '2026-05-15');
    $job->handle();

    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->gross_sales)->toBe('0.000000')
        ->and($metric->discounts)->toBe('0.000000')
        ->and($metric->refunds)->toBe('80.000000')
        ->and($metric->net_sales)->toBe('-80.000000')
        ->and($metric->units_sold)->toBe(-1)
        ->and($metric->order_count)->toBe(0);
});

it('enforces tenant isolation across shops', function () {
    $shop1Id = (string) Str::uuid();
    $shop2Id = (string) Str::uuid();

    DB::table('shops')->insert([
        ['id' => $shop1Id, 'shop_domain' => 'tenant1.myshopify.com', 'currency_code' => 'USD', 'timezone' => 'UTC', 'status' => 'active', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['id' => $shop2Id, 'shop_domain' => 'tenant2.myshopify.com', 'currency_code' => 'USD', 'timezone' => 'UTC', 'status' => 'active', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $prod1Id = (string) Str::uuid();
    $prod2Id = (string) Str::uuid();
    DB::table('products')->insert([
        ['id' => $prod1Id, 'shop_id' => $shop1Id, 'shopify_product_gid' => 'gid://shopify/Product/9001', 'title' => 'Shop 1 Product', 'handle' => 'p1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $prod2Id, 'shop_id' => $shop2Id, 'shopify_product_gid' => 'gid://shopify/Product/9002', 'title' => 'Shop 2 Product', 'handle' => 'p2', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $order1Id = (string) Str::uuid();
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        ['id' => $order1Id, 'shop_id' => $shop1Id, 'shopify_order_gid' => 'gid://shopify/Order/8001', 'order_name' => '#1', 'currency_code' => 'USD', 'processed_at' => '2026-05-15T10:00:00Z', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $order2Id, 'shop_id' => $shop2Id, 'shopify_order_gid' => 'gid://shopify/Order/8002', 'order_name' => '#2', 'currency_code' => 'USD', 'processed_at' => '2026-05-15T10:00:00Z', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('order_line_items')->insert([
        ['id' => (string) Str::uuid(), 'shop_id' => $shop1Id, 'order_id' => $order1Id, 'product_id' => $prod1Id, 'shopify_line_item_gid' => 'gid://shopify/LineItem/7001', 'title_snapshot' => 'P1', 'quantity' => 1, 'original_unit_price' => '10.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'shop_id' => $shop2Id, 'order_id' => $order2Id, 'product_id' => $prod2Id, 'shopify_line_item_gid' => 'gid://shopify/LineItem/7002', 'title_snapshot' => 'P2', 'quantity' => 5, 'original_unit_price' => '50.000000', 'discount_amount' => '0.000000', 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Aggregate Shop 1
    $job = new AggregateProductDailyMetricsJob($shop1Id, $prod1Id, '2026-05-15');
    $job->handle();

    $shop1Metric = DB::table('product_daily_metrics')->where('shop_id', $shop1Id)->first();
    $shop2Metric = DB::table('product_daily_metrics')->where('shop_id', $shop2Id)->first();

    expect($shop1Metric)->not->toBeNull()
        ->and($shop1Metric->gross_sales)->toBe('10.000000')
        ->and($shop1Metric->units_sold)->toBe(1)
        ->and($shop2Metric)->toBeNull();
});

it('aggregates orders and refunds placed at fractional subsecond timestamps up to day boundary', function () {
    $shopId = (string) Str::uuid();
    $currency = 'USD';
    $timezone = 'America/New_York'; // UTC-4 during EDT in May

    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'fractional-test.myshopify.com',
        'currency_code' => $currency,
        'timezone' => $timezone,
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/9991',
        'title' => 'Edge Case Product',
        'handle' => 'edge-case-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Metric Date: 2026-05-15
    // In America/New_York (EDT, UTC-4), 2026-05-15 23:59:59.500000 EDT is 2026-05-16 03:59:59.500000 UTC
    // Notice this fractional subsecond falls between 23:59:59.000000 and 00:00:00.000000 of next day
    $orderAtFractionalSecond = '2026-05-16 03:59:59.500000+00';
    $order1Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order1Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/7771',
        'order_name' => '#7771',
        'currency_code' => $currency,
        'processed_at' => $orderAtFractionalSecond,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $line1Id = (string) Str::uuid();
    DB::table('order_line_items')->insert([
        'id' => $line1Id,
        'shop_id' => $shopId,
        'order_id' => $order1Id,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/7772',
        'title_snapshot' => 'Edge Case Product',
        'quantity' => 1,
        'original_unit_price' => '100.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Refund processed at fractional second: 2026-05-15 23:59:59.850000 EDT -> 2026-05-16 03:59:59.850000 UTC
    DB::table('refund_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $order1Id,
        'line_item_id' => $line1Id,
        'shopify_refund_gid' => 'gid://shopify/Refund/7773',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/7774',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '20.000000',
        'currency_code' => $currency,
        'processed_at' => '2026-05-16 03:59:59.850000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Order processed at exact next day midnight: 2026-05-16 00:00:00.000000 EDT -> 2026-05-16 04:00:00.000000 UTC
    // This MUST be excluded from 2026-05-15 metrics
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order2Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/7775',
        'order_name' => '#7775',
        'currency_code' => $currency,
        'processed_at' => '2026-05-16 04:00:00.000000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $order2Id,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/7776',
        'title_snapshot' => 'Edge Case Product',
        'quantity' => 1,
        'original_unit_price' => '500.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, '2026-05-15');
    $job->handle();

    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->gross_sales)->toBe('100.000000')
        ->and($metric->discounts)->toBe('0.000000')
        ->and($metric->refunds)->toBe('20.000000')
        ->and($metric->net_sales)->toBe('80.000000')
        ->and($metric->units_sold)->toBe(0)
        ->and($metric->order_count)->toBe(1);
});
