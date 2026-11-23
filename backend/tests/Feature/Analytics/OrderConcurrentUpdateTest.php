<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Analytics\Services\StoreTimezoneService;
use App\Events\OrdersIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    $this->product = DB::table('products')->where('shop_id', $this->shopId)->first();
    $this->timezoneService = app(StoreTimezoneService::class);
});

it('acquires deterministic row locks in GID order to prevent concurrency deadlocks', function (): void {
    // Ingest orders 1 and 2
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1), orderPayload(2)]])->assertOk();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    // Ingest update to both orders (sent in reverse GID order)
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(2, [['quantity' => 2]], ['order_name' => '#2-updated', 'shopify_updated_at' => '2026-11-20T12:00:00Z']),
            orderPayload(1, [['quantity' => 1]], ['order_name' => '#1-updated', 'shopify_updated_at' => '2026-11-20T12:00:00Z']),
        ],
    ])->assertOk();

    // Verify lock query was executed with deterministic ORDER BY and FOR UPDATE
    $lockQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'for update') && str_contains($sql, '"orders"'));
    expect($lockQueries)->not->toBeEmpty();

    $lockSql = array_values($lockQueries)[0];
    expect($lockSql)->toContain('order by "shopify_order_gid" asc')
        ->and($lockSql)->toContain('for update');
});

it('rejects out-of-order order updates preserving newer data and suppressing outbox events', function (): void {
    Event::fake([OrdersIngested::class]);

    // Initial newer order update
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'name' => '#1001-NEW',
                'shopify_updated_at' => '2026-05-15T14:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    Event::assertDispatched(OrdersIngested::class, 1);
    $initialOutboxCount = DB::table('outbox_events')->where('shop_id', $this->shopId)->count();

    // Stale out-of-order update arriving later with older shopify_updated_at
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'name' => '#1001-STALE',
                'shopify_updated_at' => '2026-05-15T13:00:00Z', // 1 hour older
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    // Database state must be preserved
    $order = DB::table('orders')->where('shopify_order_gid', 'gid://shopify/Order/1')->first();
    expect($order->order_name)->toBe('#1001-NEW')
        ->and(CarbonImmutable::parse($order->shopify_updated_at)->toISOString())->toBe('2026-05-15T14:00:00.000000Z');

    // No new outbox event emitted for dropped stale order update
    expect(DB::table('outbox_events')->where('shop_id', $this->shopId)->count())->toBe($initialOutboxCount);
    Event::assertDispatched(OrdersIngested::class, 1);
});

it('guarantees duplicate order webhook deliveries never duplicate rows or double-count daily metrics', function (): void {
    $orderData = orderPayload(1, [
        'processed_at' => '2026-05-15T12:00:00Z',
        'shopify_updated_at' => '2026-05-15T12:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'quantity' => 3,
                'original_unit_price' => '25.000000',
                'discount_amount' => '5.000000',
            ]),
        ],
    ]);

    // First delivery (initial ingest)
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderData]])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    // Run precomputation job for the order date
    $job = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $job->handle($this->timezoneService);

    $metric1 = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    // 3 units * $25 = $75 gross sales, $5 discount, $70 net sales
    expect((int) $metric1->units_sold)->toBe(3)
        ->and((int) $metric1->order_count)->toBe(1)
        ->and($metric1->gross_sales)->toBe('75.000000')
        ->and($metric1->discounts)->toBe('5.000000')
        ->and($metric1->net_sales)->toBe('70.000000');

    // Second delivery (duplicate webhook retry with exact same payload)
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderData]])
        ->assertOk();

    // Third delivery (another duplicate retry)
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderData]])
        ->assertOk();

    // Zero duplicate rows in orders or line items
    expect(DB::table('orders')->where('shop_id', $this->shopId)->count())->toBe(1)
        ->and(DB::table('order_line_items')->where('shop_id', $this->shopId)->count())->toBe(1);

    // Re-run aggregation job
    $job->handle($this->timezoneService);

    $metric2 = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    // Verified: Exactly identical precomputed values with ZERO double-counting!
    expect((int) $metric2->units_sold)->toBe(3)
        ->and((int) $metric2->order_count)->toBe(1)
        ->and($metric2->gross_sales)->toBe('75.000000')
        ->and($metric2->discounts)->toBe('5.000000')
        ->and($metric2->net_sales)->toBe('70.000000');
});

it('accurately recalculates both buckets when an order is updated across calendar dates', function (): void {
    // 1. Initial order placed on 2026-05-10
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'processed_at' => '2026-05-10T12:00:00Z',
                'shopify_updated_at' => '2026-05-10T12:00:00Z',
                'line_items' => [
                    lineItemPayload(1, 1, 1, [
                        'quantity' => 2,
                        'original_unit_price' => '40.000000',
                        'discount_amount' => '0.000000',
                    ]),
                ],
            ]),
        ],
    ])->assertOk();

    $jobOldDate = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-10');
    $jobOldDate->handle($this->timezoneService);

    $metricMay10 = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-10')
        ->first();

    expect((int) $metricMay10->units_sold)->toBe(2)
        ->and($metricMay10->gross_sales)->toBe('80.000000');

    // 2. Order date modified in Shopify to 2026-05-11 with newer shopify_updated_at
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'processed_at' => '2026-05-11T12:00:00Z',
                'shopify_updated_at' => '2026-05-11T09:00:00Z',
                'line_items' => [
                    lineItemPayload(1, 1, 1, [
                        'quantity' => 2,
                        'original_unit_price' => '40.000000',
                        'discount_amount' => '0.000000',
                    ]),
                ],
            ]),
        ],
    ])->assertOk();

    // Aggregations execute for both previous bucket (2026-05-10) and updated bucket (2026-05-11)
    $jobOldDate->handle($this->timezoneService);
    $jobNewDate = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-11');
    $jobNewDate->handle($this->timezoneService);

    $metricMay10After = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-10')
        ->first();

    $metricMay11After = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-11')
        ->first();

    // Previous date is cleanly reset to 0 (no lingering ghost metrics)
    expect((int) $metricMay10After->units_sold)->toBe(0)
        ->and((int) $metricMay10After->order_count)->toBe(0)
        ->and($metricMay10After->gross_sales)->toBe('0.000000')
        // New date has the 2 units and $80
        ->and((int) $metricMay11After->units_sold)->toBe(2)
        ->and((int) $metricMay11After->order_count)->toBe(1)
        ->and($metricMay11After->gross_sales)->toBe('80.000000');
});
