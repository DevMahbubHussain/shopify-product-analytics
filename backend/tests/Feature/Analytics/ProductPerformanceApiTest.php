<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop();
});

function s2sProductsGet(string $path, array $query = [], string $shopDomain = 'acme.myshopify.com'): TestResponse
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

it('returns paginated product performance table sorted by net_sales descending', function () {
    $prodAId = (string) Str::uuid();
    $prodBId = (string) Str::uuid();
    $prodCId = (string) Str::uuid();

    DB::table('products')->insert([
        [
            'id' => $prodAId,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/1',
            'title' => 'Silk Shirt',
            'handle' => 'silk-shirt',
            'vendor' => 'Acme',
            'product_type' => 'Apparel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $prodBId,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/2',
            'title' => 'Denim Jeans',
            'handle' => 'denim-jeans',
            'vendor' => 'DenimCo',
            'product_type' => 'Apparel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $prodCId,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/3',
            'title' => 'Wool Scarf',
            'handle' => 'wool-scarf',
            'vendor' => 'Acme',
            'product_type' => 'Accessories',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Product A: 180 net sales
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'product_id' => $prodAId,
        'metric_date' => '2026-05-12',
        'currency_code' => 'USD',
        'gross_sales' => '200.000000',
        'discounts' => '20.000000',
        'refunds' => '0.000000',
        'net_sales' => '180.000000',
        'units_sold' => 2,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Product B: 450 net sales
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'product_id' => $prodBId,
        'metric_date' => '2026-05-14',
        'currency_code' => 'USD',
        'gross_sales' => '500.000000',
        'discounts' => '50.000000',
        'refunds' => '0.000000',
        'net_sales' => '450.000000',
        'units_sold' => 5,
        'order_count' => 3,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Product C: 50 net sales
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'product_id' => $prodCId,
        'metric_date' => '2026-05-15',
        'currency_code' => 'USD',
        'gross_sales' => '50.000000',
        'discounts' => '0.000000',
        'refunds' => '0.000000',
        'net_sales' => '50.000000',
        'units_sold' => 1,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sProductsGet('/api/v1/products', [
        'from' => '2026-05-10',
        'to' => '2026-05-20',
        'sort' => 'net_sales',
        'direction' => 'desc',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'shopify_product_gid',
                    'title',
                    'handle',
                    'vendor',
                    'product_type',
                    'status',
                    'gross_sales',
                    'discounts',
                    'refunds',
                    'net_sales',
                    'units_sold',
                    'orders',
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);

    $data = $response->json('data');
    expect($data)->toHaveCount(3)
        ->and($data[0]['title'])->toBe('Denim Jeans')
        ->and($data[0]['net_sales'])->toBe('450.000000')
        ->and($data[1]['title'])->toBe('Silk Shirt')
        ->and($data[1]['net_sales'])->toBe('180.000000')
        ->and($data[2]['title'])->toBe('Wool Scarf')
        ->and($data[2]['net_sales'])->toBe('50.000000');
});

it('filters products by search, vendor, and product_type', function () {
    $prodAId = (string) Str::uuid();
    $prodBId = (string) Str::uuid();

    DB::table('products')->insert([
        [
            'id' => $prodAId,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/1',
            'title' => 'Silk Shirt',
            'handle' => 'silk-shirt',
            'vendor' => 'Acme',
            'product_type' => 'Apparel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $prodBId,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/2',
            'title' => 'Denim Jeans',
            'handle' => 'denim-jeans',
            'vendor' => 'DenimCo',
            'product_type' => 'Apparel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Search by title
    $searchResponse = s2sProductsGet('/api/v1/products', [
        'from' => '2026-05-10',
        'to' => '2026-05-20',
        'search' => 'Jeans',
    ]);
    expect($searchResponse->json('data'))->toHaveCount(1)
        ->and($searchResponse->json('data.0.title'))->toBe('Denim Jeans');

    // Filter by vendor
    $vendorResponse = s2sProductsGet('/api/v1/products', [
        'from' => '2026-05-10',
        'to' => '2026-05-20',
        'vendor' => 'Acme',
    ]);
    expect($vendorResponse->json('data'))->toHaveCount(1)
        ->and($vendorResponse->json('data.0.title'))->toBe('Silk Shirt');
});

it('includes products with zero sales in the selected date window', function () {
    DB::table('products')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/10',
        'title' => 'Unsold Hat',
        'handle' => 'unsold-hat',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sProductsGet('/api/v1/products', [
        'from' => '2026-05-10',
        'to' => '2026-05-20',
    ]);

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.title'))->toBe('Unsold Hat')
        ->and($response->json('data.0.gross_sales'))->toBe('0.000000')
        ->and($response->json('data.0.net_sales'))->toBe('0.000000')
        ->and($response->json('data.0.units_sold'))->toBe(0)
        ->and($response->json('data.0.orders'))->toBe(0);
});

it('rejects invalid sort fields with 422', function () {
    s2sProductsGet('/api/v1/products', [
        'from' => '2026-05-10',
        'to' => '2026-05-20',
        'sort' => 'invalid_column',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort']);
});
