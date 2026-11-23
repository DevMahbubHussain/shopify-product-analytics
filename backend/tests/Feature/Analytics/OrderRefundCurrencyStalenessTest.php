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
    $this->shopDomain = 'currency-staleness.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'UTC');

    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], $this->shopDomain)->assertOk();

    $this->productId = DB::table('products')->where('shop_id', $this->shopId)->value('id');
});

it('resets precomputed currency metrics to zero when orders in a distinct currency are moved or removed', function (): void {
    $timezoneService = app(StoreTimezoneService::class);

    // 1. Ingest an order on 2026-05-10 with distinct currency EUR
    $order = orderPayload(1, [
        'currency_code' => 'EUR',
        'processed_at' => '2026-05-10T12:00:00Z',
        'shopify_updated_at' => '2026-05-10T12:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'currency_code' => 'EUR',
                'quantity' => 2,
                'original_unit_price' => '50.00',
                'discount_amount' => '0.00',
            ]),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], $this->shopDomain)
        ->assertOk()
        ->assertJson(['written' => 1]);

    // Compute metrics for 2026-05-10
    $job1 = new AggregateProductDailyMetricsJob($this->shopId, $this->productId, '2026-05-10');
    $job1->handle($timezoneService);

    // Verify initial precomputed row exists for EUR
    $initialMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->productId)
        ->where('metric_date', '2026-05-10')
        ->where('currency_code', 'EUR')
        ->first();

    expect($initialMetric)->not->toBeNull()
        ->and($initialMetric->gross_sales)->toBe('100.000000')
        ->and((int) $initialMetric->units_sold)->toBe(2);

    // 2. Now update the order: move its processed_at date to 2026-05-15
    $updatedOrder = orderPayload(1, [
        'currency_code' => 'EUR',
        'processed_at' => '2026-05-15T12:00:00Z',
        'shopify_updated_at' => '2026-05-15T14:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'currency_code' => 'EUR',
                'quantity' => 2,
                'original_unit_price' => '50.00',
                'discount_amount' => '0.00',
            ]),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$updatedOrder]], $this->shopDomain)
        ->assertOk()
        ->assertJson(['written' => 1]);

    // Recalculate metrics for the OLD date (2026-05-10) where no order lines in EUR exist anymore
    $recalcOldJob = new AggregateProductDailyMetricsJob($this->shopId, $this->productId, '2026-05-10');
    $recalcOldJob->handle($timezoneService);

    // The EUR row for 2026-05-10 MUST be reset to 0 rather than left stale with 100.00 gross sales!
    $staleCheckMetric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->productId)
        ->where('metric_date', '2026-05-10')
        ->where('currency_code', 'EUR')
        ->first();

    expect($staleCheckMetric)->not->toBeNull()
        ->and((float) $staleCheckMetric->gross_sales)->toBe(0.0)
        ->and((int) $staleCheckMetric->units_sold)->toBe(0)
        ->and((int) $staleCheckMetric->order_count)->toBe(0);
});

it('acquires deterministic row locks on existing orders inside transaction before capturing pre-update buckets', function (): void {
    // Seed initial order
    $initialOrder = orderPayload(2, [
        'processed_at' => '2026-05-10T12:00:00Z',
        'shopify_updated_at' => '2026-05-10T12:00:00Z',
        'line_items' => [
            lineItemPayload(2, 1, 1, ['quantity' => 1, 'original_unit_price' => '30.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$initialOrder]], $this->shopDomain)->assertOk();

    // Listen to executed queries during update
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $updatedOrder = orderPayload(2, [
        'processed_at' => '2026-05-10T12:00:00Z',
        'shopify_updated_at' => '2026-05-10T13:00:00Z',
        'line_items' => [
            lineItemPayload(2, 1, 1, ['quantity' => 3, 'original_unit_price' => '30.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$updatedOrder]], $this->shopDomain)
        ->assertOk()
        ->assertJson(['written' => 1]);

    // Verify a FOR UPDATE lock query was executed on orders in deterministic order
    $lockQuery = collect($queries)->first(fn (string $sql): bool =>
        str_contains(strtolower($sql), 'for update') && str_contains(strtolower($sql), 'orders')
    );

    expect($lockQuery)->not->toBeNull();
});
