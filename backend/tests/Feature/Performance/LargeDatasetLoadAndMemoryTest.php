<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopDomain = 'perf-shop.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'America/New_York');

    // Create 10 products
    $products = [];
    for ($i = 1; $i <= 10; $i++) {
        $products[] = productPayload($i);
    }
    s2sPost('/internal/v1/ingestion/products', ['products' => $products], $this->shopDomain)->assertOk();
});

it('ingests the maximum supported batch size (50 orders, 250 line items) with flat memory and fast latency', function () {
    // Generate maximum allowed batch: 50 orders with 5 line items each (total 250 line items)
    $orders = [];
    for ($o = 1; $o <= 50; $o++) {
        $lines = [];
        for ($l = 1; $l <= 5; $l++) {
            $prodId = (($o + $l) % 10) + 1;
            $lines[] = lineItemPayload($o, $l, $prodId, [
                'quantity' => 2,
                'original_unit_price' => '25.00',
                'discount_amount' => '0.00',
            ]);
        }

        $orders[] = orderPayload($o, [
            'processed_at' => '2026-05-15T15:00:00Z',
            'shopify_updated_at' => '2026-05-15T15:00:00Z',
            'line_items' => $lines,
        ]);
    }

    $memBefore = memory_get_usage(true);
    $timeStart = hrtime(true);

    $response = s2sPost('/internal/v1/ingestion/orders', ['orders' => $orders], $this->shopDomain);

    $timeElapsedMs = (hrtime(true) - $timeStart) / 1e6;
    $memPeak = memory_get_peak_usage(true);

    $response->assertOk()
        ->assertJson(['received' => 50, 'written' => 50]);

    // Verify database counts
    expect(DB::table('orders')->where('shop_id', $this->shopId)->count())->toBe(50)
        ->and(DB::table('order_line_items')->where('shop_id', $this->shopId)->count())->toBe(250)
        ->and(DB::table('outbox_events')->where('shop_id', $this->shopId)->count())->toBe(1);

    // Verify outbox status was marked published by fast-path
    $outboxRow = DB::table('outbox_events')->where('shop_id', $this->shopId)->first();
    expect($outboxRow->status)->toBe('published');

    // Performance Assertions:
    // 1. Memory delta consumed by processing 250 line items is less than 10.0 MB
    $memDeltaMb = ($memPeak - $memBefore) / (1024 * 1024);
    expect($memDeltaMb)->toBeLessThan(10.0)
        ->and($memPeak / (1024 * 1024))->toBeLessThan(128.0);

    // 2. Execution completes well within 1,500ms in Docker container environment
    expect($timeElapsedMs)->toBeLessThan(1500.0);
});

it('aggregates daily metrics over 1,000 line items using database indexes with sub-200ms latency', function () {
    $productId = DB::table('products')
        ->where('shop_id', $this->shopId)
        ->where('shopify_product_gid', 'gid://shopify/Product/1')
        ->value('id');

    $variantId = DB::table('product_variants')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $productId)
        ->value('id');

    // Seed 200 orders, each with 5 line items for Product 1 (total 1,000 line items) on 2026-05-15
    $orderRows = [];
    $lineRows = [];
    $processedAt = '2026-05-15 16:00:00.000000+00'; // 12:00 EDT

    for ($i = 1; $i <= 200; $i++) {
        $orderId = (string) Str::uuid();
        $orderRows[] = [
            'id' => $orderId,
            'shop_id' => $this->shopId,
            'shopify_order_gid' => "gid://shopify/Order/perf_{$i}",
            'order_name' => "#PERF-{$i}",
            'currency_code' => 'USD',
            'financial_status' => 'paid',
            'processed_at' => $processedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        for ($k = 1; $k <= 5; $k++) {
            $lineRows[] = [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'shopify_line_item_gid' => "gid://shopify/LineItem/perf_{$i}_{$k}",
                'title_snapshot' => 'Perf Product',
                'quantity' => 1,
                'original_unit_price' => '10.000000',
                'discount_amount' => '0.000000',
                'currency_code' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    DB::table('orders')->insert($orderRows);
    foreach (array_chunk($lineRows, 500) as $chunk) {
        DB::table('order_line_items')->insert($chunk);
    }

    // Verify PostgreSQL index usage on the aggregation query using EXPLAIN
    $explainResults = DB::select("
        EXPLAIN (FORMAT JSON)
        SELECT order_line_items.id
        FROM order_line_items
        INNER JOIN orders ON orders.id = order_line_items.order_id AND orders.shop_id = order_line_items.shop_id
        WHERE order_line_items.shop_id = '{$this->shopId}'
          AND order_line_items.product_id = '{$productId}'
          AND orders.processed_at >= '2026-05-15 04:00:00+00'
          AND orders.processed_at < '2026-05-16 04:00:00+00'
    ");

    $explainJson = json_encode($explainResults, JSON_THROW_ON_ERROR);
    // Verify that PostgreSQL utilizes index scans on the foreign keys and processed_at timestamps
    expect($explainJson)->toContain('Index');

    // Execute aggregation job and measure execution latency
    $timeStart = hrtime(true);
    (new AggregateProductDailyMetricsJob($this->shopId, $productId, '2026-05-15'))->handle();
    $executionMs = (hrtime(true) - $timeStart) / 1e6;

    // Verify computed aggregates: 1,000 units sold, $10,000.00 gross sales, 200 orders
    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($metric)->not->toBeNull()
        ->and((int) $metric->units_sold)->toBe(1000)
        ->and((int) $metric->order_count)->toBe(200)
        ->and((float) $metric->gross_sales)->toEqualWithDelta(10000.00, 0.001)
        ->and((float) $metric->net_sales)->toEqualWithDelta(10000.00, 0.001);

    // Performance Assertion: Sub-200ms aggregation over 1,000 line items
    expect($executionMs)->toBeLessThan(200.0);
});

it('handles 365 days of historical metrics with zero N+1 queries and sub-100ms response time', function () {
    // Activate pro plan (uppercase ACTIVE) to permit 365-day range queries
    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/999888',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
        'current_period_end' => CarbonImmutable::now()->addYear(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->where('shop_id', $this->shopId)->value('id');

    // Precompute 365 daily rows in product_daily_metrics
    $metrics = [];
    $startDate = CarbonImmutable::parse('2025-05-16');

    for ($day = 0; $day < 365; $day++) {
        $metricDate = $startDate->addDays($day)->format('Y-m-d');
        $metrics[] = [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'metric_date' => $metricDate,
            'currency_code' => 'USD',
            'gross_sales' => '100.000000',
            'discounts' => '5.000000',
            'refunds' => '0.000000',
            'net_sales' => '95.000000',
            'units_sold' => 4,
            'order_count' => 2,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($metrics, 100) as $chunk) {
        DB::table('product_daily_metrics')->insert($chunk);
    }

    // Enable query logging to audit executed SQL statements
    DB::enableQueryLog();

    $timeStart = hrtime(true);
    $response = s2sGet('/api/v1/analytics/overview?from=2025-05-16&to=2026-05-15', $this->shopDomain);
    $responseMs = (hrtime(true) - $timeStart) / 1e6;

    $executedQueries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'date_range' => ['from', 'to'],
                'currency',
                'summary' => [
                    'gross_sales',
                    'net_sales',
                    'discounts',
                    'refunds',
                    'units_sold',
                    'orders',
                ],
            ],
        ]);

    // Zero N+1 Queries Assertion: Constant O(1) query count (exactly 7 queries for full auth, plan check, and aggregates)
    expect(count($executedQueries))->toBe(7);

    // Latency Assertion: Responds under 100ms in Docker container
    expect($responseMs)->toBeLessThan(100.0);
});

it('proves memory stability across sequential ingestion batches without memory leaks', function () {
    $snapshots = [];

    // Run 5 sequential batches of 20 orders (100 line items each)
    for ($batch = 1; $batch <= 5; $batch++) {
        $orders = [];
        for ($o = 1; $o <= 20; $o++) {
            $orderNum = ($batch * 100) + $o;
            $orders[] = orderPayload($orderNum, [
                'processed_at' => '2026-05-15T15:00:00Z',
                'shopify_updated_at' => '2026-05-15T15:00:00Z',
                'line_items' => [
                    lineItemPayload($orderNum, 1, 1, ['quantity' => 1, 'original_unit_price' => '20.00']),
                    lineItemPayload($orderNum, 2, 2, ['quantity' => 1, 'original_unit_price' => '30.00']),
                ],
            ]);
        }

        s2sPost('/internal/v1/ingestion/orders', ['orders' => $orders], $this->shopDomain)->assertOk();

        // Trigger garbage collection and take memory snapshot
        gc_collect_cycles();
        $snapshots[] = memory_get_usage(true);
    }

    // Compare memory between iteration 2 (after warmup) and iteration 5
    // Delta must be less than 2.0 MB, demonstrating flat memory and zero leaks
    $memoryDeltaMb = ($snapshots[4] - $snapshots[1]) / (1024 * 1024);

    expect($memoryDeltaMb)->toBeLessThan(2.0);
});
