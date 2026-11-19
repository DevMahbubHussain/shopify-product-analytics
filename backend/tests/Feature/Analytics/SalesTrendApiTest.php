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

function s2sTrendGet(string $path, array $query = [], string $shopDomain = 'acme.myshopify.com'): TestResponse
{
    config()->set('services.s2s.secret', INGESTION_TEST_SECRET);

    $normalizedPath = '/' . ltrim($path, '/');
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

it('returns daily time-series sales trend with zero gap filling', function () {
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

    // Data only on 2026-05-10 and 2026-05-12
    DB::table('product_daily_metrics')->insert([
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'metric_date' => '2026-05-10',
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
            'metric_date' => '2026-05-12',
            'currency_code' => 'USD',
            'gross_sales' => '150.000000',
            'discounts' => '0.000000',
            'refunds' => '20.000000',
            'net_sales' => '130.000000',
            'units_sold' => 3,
            'order_count' => 1,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = s2sTrendGet('/api/v1/analytics/sales-trend', [
        'from' => '2026-05-10',
        'to' => '2026-05-14',
        'interval' => 'day',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'interval',
                'currency',
                'points' => [
                    '*' => [
                        'date',
                        'gross_sales',
                        'discounts',
                        'refunds',
                        'net_sales',
                        'units_sold',
                        'orders',
                    ],
                ],
            ],
        ]);

    $points = $response->json('data.points');
    expect($points)->toHaveCount(5);

    // May 10 has real data
    expect($points[0]['date'])->toBe('2026-05-10')
        ->and($points[0]['gross_sales'])->toBe('100.000000')
        ->and($points[0]['net_sales'])->toBe('90.000000')
        ->and($points[0]['units_sold'])->toBe(2);

    // May 11 is zero-filled
    expect($points[1]['date'])->toBe('2026-05-11')
        ->and($points[1]['gross_sales'])->toBe('0.000000')
        ->and($points[1]['net_sales'])->toBe('0.000000')
        ->and($points[1]['units_sold'])->toBe(0);

    // May 12 has real data
    expect($points[2]['date'])->toBe('2026-05-12')
        ->and($points[2]['gross_sales'])->toBe('150.000000')
        ->and($points[2]['net_sales'])->toBe('130.000000')
        ->and($points[2]['units_sold'])->toBe(3);

    // May 13 & 14 are zero-filled
    expect($points[3]['date'])->toBe('2026-05-13')
        ->and($points[3]['net_sales'])->toBe('0.000000');
    expect($points[4]['date'])->toBe('2026-05-14')
        ->and($points[4]['net_sales'])->toBe('0.000000');
});

it('aggregates sales trend by month interval', function () {
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/2',
        'title' => 'Cap',
        'handle' => 'cap',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('product_daily_metrics')->insert([
        [
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'metric_date' => '2026-01-15',
            'currency_code' => 'USD',
            'gross_sales' => '100.000000',
            'discounts' => '0.000000',
            'refunds' => '0.000000',
            'net_sales' => '100.000000',
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
            'metric_date' => '2026-02-20',
            'currency_code' => 'USD',
            'gross_sales' => '200.000000',
            'discounts' => '0.000000',
            'refunds' => '0.000000',
            'net_sales' => '200.000000',
            'units_sold' => 4,
            'order_count' => 2,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = s2sTrendGet('/api/v1/analytics/sales-trend', [
        'from' => '2026-01-01',
        'to' => '2026-03-31',
        'interval' => 'month',
    ]);

    $response->assertOk();
    $points = $response->json('data.points');
    expect($points)->toHaveCount(3); // Jan, Feb, Mar

    expect($points[0]['date'])->toBe('2026-01')
        ->and($points[0]['net_sales'])->toBe('100.000000');
    expect($points[1]['date'])->toBe('2026-02')
        ->and($points[1]['net_sales'])->toBe('200.000000');
    expect($points[2]['date'])->toBe('2026-03')
        ->and($points[2]['net_sales'])->toBe('0.000000');
});

it('rejects invalid interval with 422', function () {
    s2sTrendGet('/api/v1/analytics/sales-trend', [
        'from' => '2026-05-10',
        'to' => '2026-05-14',
        'interval' => 'quarter',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['interval']);
});
