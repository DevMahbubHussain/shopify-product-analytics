<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Analytics\Services\StoreTimezoneService;
use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('load-benchmark.myshopify.com', 'UTC');
});

it('verifies PostgreSQL query plans utilize specific composite indexes without table scans', function (): void {
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/bench_001',
        'title' => 'Index Probe Product',
        'handle' => 'index-probe-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/idx_001',
        'order_name' => '#1001',
        'financial_status' => 'paid',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-15 12:00:00+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/idx_001',
        'title_snapshot' => 'Index Line',
        'quantity' => 1,
        'original_unit_price' => '10.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'product_id' => $productId,
        'metric_date' => '2026-05-15',
        'currency_code' => 'USD',
        'gross_sales' => '10.000000',
        'discounts' => '0.000000',
        'refunds' => '0.000000',
        'net_sales' => '10.000000',
        'units_sold' => 1,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Disable sequential scans to force optimizer to select the best index scan plan
    DB::statement('SET enable_seqscan = OFF');

    try {
        // 1. Verify orders time-window query uses orders_shop_processed_idx
        $ordersPlan = DB::select("
            EXPLAIN
            SELECT * FROM orders
            WHERE shop_id = '{$this->shopId}'::uuid
              AND processed_at >= '2026-05-15 00:00:00+00'::timestamptz
              AND processed_at < '2026-05-16 00:00:00+00'::timestamptz
        ");
        $ordersPlanText = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $ordersPlan));

        expect($ordersPlanText)->toContain('orders_shop_processed_idx')
            ->and($ordersPlanText)->not->toContain('Seq Scan');

        // 2. Verify order_line_items tenant query uses line_items composite index
        $lineItemsPlan = DB::select("
            EXPLAIN
            SELECT * FROM order_line_items
            WHERE shop_id = '{$this->shopId}'::uuid
              AND order_id = '{$orderId}'::uuid
        ");
        $linePlanText = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $lineItemsPlan));

        expect($linePlanText)->toContain('line_items_shop_order_idx')
            ->and($linePlanText)->not->toContain('Seq Scan');

        // 3. Verify product_daily_metrics uses composite index
        $metricsPlan = DB::select("
            EXPLAIN
            SELECT * FROM product_daily_metrics
            WHERE shop_id = '{$this->shopId}'::uuid
              AND metric_date = '2026-05-15'::date
        ");
        $metricsPlanText = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $metricsPlan));

        expect($metricsPlanText)->toContain('metrics_shop_date_idx')
            ->and($metricsPlanText)->not->toContain('Seq Scan');

        // 4. Verify outbox stranded event recovery uses outbox_events_status_created_idx
        $outboxRecoveryPlan = DB::select("
            EXPLAIN
            SELECT * FROM outbox_events
            WHERE status = 'pending' AND created_at <= now()
            ORDER BY created_at ASC LIMIT 100
            FOR UPDATE SKIP LOCKED
        ");
        $outboxPlanText = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $outboxRecoveryPlan));

        expect($outboxPlanText)->toContain('outbox_events_status_created_idx')
            ->and($outboxPlanText)->not->toContain('Seq Scan');
    } finally {
        DB::statement('SET enable_seqscan = ON');
    }
});

it('verifies exact query count discipline on batch ingestion preventing N+1 regressions', function (): void {
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1), productPayload(2)],
    ], 'load-benchmark.myshopify.com')->assertOk();

    $orders = array_map(
        fn (int $o): array => orderPayload($o, [
            'line_items' => array_map(fn (int $l): array => lineItemPayload($o, $l, ($l % 2) + 1), range(1, 10)),
        ]),
        range(1, 25),
    );

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    s2sPost('/internal/v1/ingestion/orders', ['orders' => $orders], 'load-benchmark.myshopify.com')
        ->assertOk()
        ->assertJson(['written' => 25]);

    // Exact query count is 11:
    // 1: Lookup shop by domain
    // 2: Lookup order timestamps by GIDs
    // 3: Capture pre-upsert buckets by order GIDs
    // 4: Upsert orders batch (25 rows)
    // 5: Lookup products by GIDs
    // 6: Lookup product variants by GIDs
    // 7: Delete pruned line items (batch bulk)
    // 8: Upsert order_line_items batch (250 rows)
    // 9: Capture post-upsert buckets by order IDs
    // 10: Insert outbox_events record
    // 11: Update outbox_events record to published (WHERE id = ? AND status = 'pending')
    expect(DB::table('order_line_items')->where('shop_id', $this->shopId)->count())->toBe(250)
        ->and(count($queries))->toBe(11);
});

it('benchmarks aggregation latency, throughput, and isolated peak memory across 1k, 10k, and 50k scales', function (): void {
    DB::disableQueryLog();

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/bench_scale',
        'title' => 'Scale Benchmark Product',
        'handle' => 'scale-benchmark-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $timezoneService = app(StoreTimezoneService::class);

    foreach ([1000, 10000, 50000] as $lineCount) {
        $orderCount = (int) ($lineCount / 10);

        // Seed orders and line items using PostgreSQL native generate_series
        DB::statement("
            INSERT INTO orders (id, shop_id, shopify_order_gid, order_name, financial_status, currency_code, processed_at, created_at, updated_at)
            SELECT gen_random_uuid(), '{$this->shopId}'::uuid, 'gid://shopify/Order/scale_{$lineCount}_' || g, '#' || g, 'paid', 'USD', '2026-05-15 12:00:00+00'::timestamptz, now(), now()
            FROM generate_series(1, {$orderCount}) AS g;
        ");

        DB::statement("
            INSERT INTO order_line_items (id, shop_id, order_id, product_id, shopify_line_item_gid, title_snapshot, quantity, original_unit_price, discount_amount, currency_code, created_at, updated_at)
            SELECT gen_random_uuid(), '{$this->shopId}'::uuid, o.id, '{$productId}'::uuid, 'gid://shopify/LineItem/' || o.id || '_' || l, 'Benchmark Line', 2, 20.000000, 1.000000, 'USD', now(), now()
            FROM orders o CROSS JOIN generate_series(1, 10) AS l
            WHERE o.shop_id = '{$this->shopId}'::uuid;
        ");

        // Force GC and reset isolated peak memory tracking
        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $memBefore = memory_get_usage();
        $startTime = microtime(true);

        $job = new AggregateProductDailyMetricsJob($this->shopId, $productId, '2026-05-15');
        $job->handle($timezoneService);

        $durationMs = (microtime(true) - $startTime) * 1000;
        gc_collect_cycles();
        $netMemoryDeltaKb = (memory_get_usage() - $memBefore) / 1024;
        $peakAllocatedKb = (memory_get_peak_usage() - $memBefore) / 1024;
        $throughput = round($lineCount / max(0.001, $durationMs / 1000));

        if ($lineCount === 1000) {
            // 1,000 lines must execute sub-150ms with net memory delta < 1MB
            expect($durationMs)->toBeLessThan(150.0)
                ->and($netMemoryDeltaKb)->toBeLessThan(1024.0);
        } elseif ($lineCount === 10000) {
            // 10,000 lines must execute sub-1,500ms with net memory delta < 2MB
            expect($durationMs)->toBeLessThan(1500.0)
                ->and($netMemoryDeltaKb)->toBeLessThan(2048.0);
        } else {
            // 50,000 lines: sub-10,000ms, throughput > 5,000 lines/sec, net memory < 10MB
            expect($durationMs)->toBeLessThan(10000.0)
                ->and($throughput)->toBeGreaterThan(5000.0)
                ->and($netMemoryDeltaKb)->toBeLessThan(10240.0);
        }

        // Verify aggregation mathematical precision
        $metric = DB::table('product_daily_metrics')
            ->where('shop_id', $this->shopId)
            ->where('product_id', $productId)
            ->first();

        $expectedUnits = $lineCount * 2;
        $expectedGross = bcmul((string) $expectedUnits, '20.000000', 6);

        expect((int) $metric->units_sold)->toBe($expectedUnits)
            ->and((int) $metric->order_count)->toBe($orderCount)
            ->and($metric->gross_sales)->toBe($expectedGross);

        // Clean up per-scale data
        DB::table('order_line_items')->where('shop_id', $this->shopId)->delete();
        DB::table('orders')->where('shop_id', $this->shopId)->delete();
        DB::table('product_daily_metrics')->where('shop_id', $this->shopId)->delete();
    }
});

it('verifies long-running queue worker memory stability across 50 sequential aggregation cycles', function (): void {
    DB::disableQueryLog();

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/bench_worker',
        'title' => 'Worker Stability Product',
        'handle' => 'worker-stability-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Seed 1,000 lines (100 orders x 10 lines)
    DB::statement("
        INSERT INTO orders (id, shop_id, shopify_order_gid, order_name, financial_status, currency_code, processed_at, created_at, updated_at)
        SELECT gen_random_uuid(), '{$this->shopId}'::uuid, 'gid://shopify/Order/w_' || g, '#' || g, 'paid', 'USD', '2026-05-15 12:00:00+00'::timestamptz, now(), now()
        FROM generate_series(1, 100) AS g;
    ");

    DB::statement("
        INSERT INTO order_line_items (id, shop_id, order_id, product_id, shopify_line_item_gid, title_snapshot, quantity, original_unit_price, discount_amount, currency_code, created_at, updated_at)
        SELECT gen_random_uuid(), '{$this->shopId}'::uuid, o.id, '{$productId}'::uuid, 'gid://shopify/LineItem/' || o.id || '_' || l, 'Worker Line', 2, 20.000000, 1.000000, 'USD', now(), now()
        FROM orders o CROSS JOIN generate_series(1, 10) AS l
        WHERE o.shop_id = '{$this->shopId}'::uuid;
    ");

    $timezoneService = app(StoreTimezoneService::class);
    $memHistory = [];

    // Execute 50 sequential aggregation cycles simulating a persistent queue worker
    for ($iter = 1; $iter <= 50; $iter++) {
        $job = new AggregateProductDailyMetricsJob($this->shopId, $productId, '2026-05-15');
        $job->handle($timezoneService);
        gc_collect_cycles();
        $memHistory[] = memory_get_usage();
    }

    // Measure post-warmup memory drift between iteration 5 and iteration 50 (45 full jobs)
    $postWarmupDriftKb = (end($memHistory) - $memHistory[4]) / 1024;

    // Assert flat memory footprint: drift across 45 jobs must not exceed 250 KB
    expect($postWarmupDriftKb)->toBeLessThan(250.0);
});

it('handles 365 days of historical precomputed metrics with exactly 7 queries and zero N+1 regressions', function (): void {
    // Activate pro plan to permit 365-day range queries
    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/bench_pro',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
        'current_period_end' => CarbonImmutable::now()->addYear(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/bench_overview',
        'title' => 'Overview Benchmark Product',
        'handle' => 'overview-benchmark-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Precompute 365 daily rows in product_daily_metrics
    $startDate = CarbonImmutable::parse('2025-05-16');
    $rows = [];
    for ($day = 0; $day < 365; $day++) {
        $metricDate = $startDate->addDays($day)->format('Y-m-d');
        $rows[] = [
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

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('product_daily_metrics')->insert($chunk);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $startTime = microtime(true);
    $response = s2sGet('/api/v1/analytics/overview?from=2025-05-16&to=2026-05-15', 'load-benchmark.myshopify.com');
    $durationMs = (microtime(true) - $startTime) * 1000;

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

    // Exact O(1) query count assertion: exactly 7 queries
    // 1: Lookup shop by domain
    // 2: Nonce duplicate check
    // 3: Verify subscription plan entitlement
    // 4: Aggregate current period metrics
    // 5: Aggregate previous period metrics for comparison
    // 6: Select currency codes
    // 7: Count active products
    expect(count($queries))->toBe(7)
        ->and($durationMs)->toBeLessThan(100.0);
});
