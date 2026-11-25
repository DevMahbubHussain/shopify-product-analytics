<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop();
});

function s2sAnalyticsGet(string $path, array $query = [], string $shopDomain = 'acme.myshopify.com'): TestResponse
{
    config()->set('services.s2s.secret', INGESTION_TEST_SECRET);

    $normalizedPath = '/'.ltrim($path, '/');
    $timestamp = (string) time();
    $nonce = (string) Str::uuid();
    $canonical = implode("\n", ['GET', $normalizedPath, $timestamp, $nonce, $shopDomain, hash('sha256', '')]);

    return test()->call('GET', $normalizedPath, $query, [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_APP_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_APP_TIMESTAMP' => $timestamp,
        'HTTP_X_APP_NONCE' => $nonce,
        'HTTP_X_APP_SIGNATURE' => hash_hmac('sha256', $canonical, INGESTION_TEST_SECRET),
    ]);
}

it('returns aggregated overview metrics and preceding period comparisons', function () {
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'title' => 'Oxford Shirt',
        'handle' => 'oxford-shirt',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Current period metrics (2026-05-11 to 2026-05-20)
    DB::table('product_daily_metrics')->insert([
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'metric_date' => '2026-05-12',
            'currency_code' => 'USD',
            'gross_sales' => '100.000000',
            'discounts' => '10.000000',
            'refunds' => '0.000000',
            'net_sales' => '90.000000',
            'units_sold' => 2,
            'order_count' => 1,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'metric_date' => '2026-05-15',
            'currency_code' => 'USD',
            'gross_sales' => '200.000000',
            'discounts' => '20.000000',
            'refunds' => '30.000000',
            'net_sales' => '150.000000',
            'units_sold' => 3,
            'order_count' => 1,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Previous period metrics (2026-05-01 to 2026-05-10)
    DB::table('product_daily_metrics')->insert([
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'metric_date' => '2026-05-05',
            'currency_code' => 'USD',
            'gross_sales' => '150.000000',
            'discounts' => '10.000000',
            'refunds' => '0.000000',
            'net_sales' => '140.000000',
            'units_sold' => 3,
            'order_count' => 1,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Orders in current period: 2 distinct orders
    DB::table('orders')->insert([
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'shopify_order_gid' => 'gid://shopify/Order/101',
            'order_name' => '#101',
            'currency_code' => 'USD',
            'processed_at' => CarbonImmutable::parse('2026-05-12T15:00:00Z'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'shopify_order_gid' => 'gid://shopify/Order/102',
            'order_name' => '#102',
            'currency_code' => 'USD',
            'processed_at' => CarbonImmutable::parse('2026-05-15T18:00:00Z'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Orders in previous period: 1 order
    DB::table('orders')->insert([
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'shopify_order_gid' => 'gid://shopify/Order/99',
            'order_name' => '#99',
            'currency_code' => 'USD',
            'processed_at' => CarbonImmutable::parse('2026-05-05T12:00:00Z'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = s2sAnalyticsGet('/api/v1/analytics/overview', [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'date_range' => [
                    'from' => '2026-05-11',
                    'to' => '2026-05-20',
                ],
                'previous_date_range' => [
                    'from' => '2026-05-01',
                    'to' => '2026-05-10',
                ],
                'currency' => 'USD',
                'summary' => [
                    'gross_sales' => '300.000000',
                    'discounts' => '30.000000',
                    'refunds' => '30.000000',
                    'net_sales' => '240.000000',
                    'units_sold' => 5,
                    'orders' => 2,
                    'average_order_revenue' => '120.000000',
                ],
                'comparison' => [
                    'gross_sales' => [
                        'delta' => '150.000000',
                        'percentage_change' => 100.0,
                        'direction' => 'increase',
                    ],
                    'net_sales' => [
                        'delta' => '100.000000',
                        'percentage_change' => 71.43,
                        'direction' => 'increase',
                    ],
                    'units_sold' => [
                        'delta' => 2,
                        'percentage_change' => 66.67,
                        'direction' => 'increase',
                    ],
                    'orders' => [
                        'delta' => 1,
                        'percentage_change' => 100.0,
                        'direction' => 'increase',
                    ],
                ],
            ],
        ]);
});

it('rejects invalid date range requests with 422', function () {
    // Missing dates
    s2sAnalyticsGet('/api/v1/analytics/overview', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'to']);

    // from > to
    s2sAnalyticsGet('/api/v1/analytics/overview', [
        'from' => '2026-05-20',
        'to' => '2026-05-10',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);

    // Not a date
    s2sAnalyticsGet('/api/v1/analytics/overview', [
        'from' => 'invalid-date',
        'to' => '2026-05-10',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from']);

    // Window > 365 days
    s2sAnalyticsGet('/api/v1/analytics/overview', [
        'from' => '2024-01-01',
        'to' => '2026-05-10',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

it('returns 403 Forbidden for an uninstalled shop', function () {
    DB::table('shops')->where('id', $this->shopId)->update([
        'status' => 'uninstalled',
        'uninstalled_at' => now(),
    ]);

    s2sAnalyticsGet('/api/v1/analytics/overview', [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ])->assertForbidden();
});

it('requires a valid S2S signature', function () {
    test()->call('GET', '/api/v1/analytics/overview?from=2026-05-11&to=2026-05-20', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertUnauthorized();
});

it('accurately counts orders placed at fractional subsecond boundary timestamps in period comparisons', function () {
    // Current period: 2026-05-11 to 2026-05-20 in America/New_York (EDT, UTC-4)
    // 2026-05-20 23:59:59.750000 EDT -> 2026-05-21 03:59:59.750000 UTC (MUST be counted in current period)
    $currentBoundaryOrderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $currentBoundaryOrderId,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/boundary-1',
        'order_name' => '#boundary-1',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-21 03:59:59.750000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Next-day midnight: 2026-05-21 00:00:00.000000 EDT -> 2026-05-21 04:00:00.000000 UTC (MUST NOT be counted in current period)
    DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/boundary-2',
        'order_name' => '#boundary-2',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-21 04:00:00.000000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Previous period (10 days preceding: 2026-05-01 to 2026-05-10)
    // 2026-05-10 23:59:59.800000 EDT -> 2026-05-11 03:59:59.800000 UTC (MUST be counted in previous period)
    DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/boundary-prev',
        'order_name' => '#boundary-prev',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-11 03:59:59.800000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sAnalyticsGet('/api/v1/analytics/overview', [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ]);

    $response->assertOk();
    expect($response->json('data.summary.orders'))->toBe(1)
        ->and($response->json('data.comparison.orders.delta'))->toBe(0);
});
