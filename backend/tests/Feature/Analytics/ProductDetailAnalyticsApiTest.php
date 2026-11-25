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

function s2sDetailGet(string $path, array $query = [], string $shopDomain = 'acme.myshopify.com'): TestResponse
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

it('returns individual product analytics summary and period comparison', function () {
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'title' => 'Vintage Denim Jacket',
        'handle' => 'vintage-denim-jacket',
        'vendor' => 'DenimCo',
        'product_type' => 'Outerwear',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Current period (2026-05-11 to 2026-05-20)
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'product_id' => $productId,
        'metric_date' => '2026-05-15',
        'currency_code' => 'USD',
        'gross_sales' => '400.000000',
        'discounts' => '40.000000',
        'refunds' => '60.000000',
        'net_sales' => '300.000000',
        'units_sold' => 4,
        'order_count' => 2,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Previous period (2026-05-01 to 2026-05-10)
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'product_id' => $productId,
        'metric_date' => '2026-05-05',
        'currency_code' => 'USD',
        'gross_sales' => '200.000000',
        'discounts' => '0.000000',
        'refunds' => '0.000000',
        'net_sales' => '200.000000',
        'units_sold' => 2,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sDetailGet("/api/v1/products/{$productId}/analytics", [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'product' => [
                    'id' => $productId,
                    'shopify_product_gid' => 'gid://shopify/Product/1',
                    'title' => 'Vintage Denim Jacket',
                    'handle' => 'vintage-denim-jacket',
                ],
                'date_range' => [
                    'from' => '2026-05-11',
                    'to' => '2026-05-20',
                ],
                'previous_date_range' => [
                    'from' => '2026-05-01',
                    'to' => '2026-05-10',
                ],
                'summary' => [
                    'gross_sales' => '400.000000',
                    'discounts' => '40.000000',
                    'refunds' => '60.000000',
                    'net_sales' => '300.000000',
                    'units_sold' => 4,
                    'orders' => 2,
                    'average_order_revenue' => '150.000000',
                ],
                'comparison' => [
                    'gross_sales' => [
                        'delta' => '200.000000',
                        'percentage_change' => 100.0,
                        'direction' => 'increase',
                    ],
                    'net_sales' => [
                        'delta' => '100.000000',
                        'percentage_change' => 50.0,
                        'direction' => 'increase',
                    ],
                    'units_sold' => [
                        'delta' => 2,
                        'percentage_change' => 100.0,
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

it('returns variant breakdown analytics for a product', function () {
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'title' => 'Vintage Denim Jacket',
        'handle' => 'vintage-denim-jacket',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $varSmallId = (string) Str::uuid();
    $varMedId = (string) Str::uuid();
    DB::table('product_variants')->insert([
        [
            'id' => $varSmallId,
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'shopify_variant_gid' => 'gid://shopify/ProductVariant/201',
            'title' => 'Small',
            'sku' => 'JCK-S',
            'price' => '50.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $varMedId,
            'shop_id' => $this->shopId,
            'product_id' => $productId,
            'shopify_variant_gid' => 'gid://shopify/ProductVariant/202',
            'title' => 'Medium',
            'sku' => 'JCK-M',
            'price' => '60.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $order1Id = (string) Str::uuid();
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        [
            'id' => $order1Id,
            'shop_id' => $this->shopId,
            'shopify_order_gid' => 'gid://shopify/Order/301',
            'order_name' => '#301',
            'currency_code' => 'USD',
            'processed_at' => CarbonImmutable::parse('2026-05-15T15:00:00Z'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $order2Id,
            'shop_id' => $this->shopId,
            'shopify_order_gid' => 'gid://shopify/Order/302',
            'order_name' => '#302',
            'currency_code' => 'USD',
            'processed_at' => CarbonImmutable::parse('2026-05-16T18:00:00Z'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Small: 2 units at 50 = 100 gross, 10 discount = 90 net
    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $order1Id,
        'product_id' => $productId,
        'variant_id' => $varSmallId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/401',
        'title_snapshot' => 'Small',
        'quantity' => 2,
        'original_unit_price' => '50.000000',
        'discount_amount' => '10.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Medium: 3 units at 60 = 180 gross, 0 discount = 180 net
    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $order2Id,
        'product_id' => $productId,
        'variant_id' => $varMedId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/402',
        'title_snapshot' => 'Medium',
        'quantity' => 3,
        'original_unit_price' => '60.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sDetailGet("/api/v1/products/{$productId}/variants/analytics", [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ]);

    $response->assertOk();
    $variants = $response->json('data.variants');
    expect($variants)->toHaveCount(2)
        ->and($variants[0]['title'])->toBe('Medium')
        ->and($variants[0]['net_sales'])->toBe('180.000000')
        ->and($variants[0]['units_sold'])->toBe(3)
        ->and($variants[1]['title'])->toBe('Small')
        ->and($variants[1]['net_sales'])->toBe('90.000000')
        ->and($variants[1]['units_sold'])->toBe(2);
});

it('returns 404 for unknown product or another shop product', function () {
    $unknownId = (string) Str::uuid();

    s2sDetailGet("/api/v1/products/{$unknownId}/analytics", [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ])->assertNotFound();
});

it('accurately includes variant orders and refunds placed at fractional subsecond boundary timestamps', function () {
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/var-boundary',
        'title' => 'Boundary Product',
        'handle' => 'boundary-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = (string) Str::uuid();
    DB::table('product_variants')->insert([
        'id' => $variantId,
        'shop_id' => $this->shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => 'gid://shopify/ProductVariant/var-boundary-1',
        'title' => 'Standard',
        'sku' => 'BND-STD',
        'price' => '100.000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Current period requested: from=2026-05-11, to=2026-05-20 in America/New_York (EDT, UTC-4)
    // Order on boundary: 2026-05-20 23:59:59.600000 EDT -> 2026-05-21 03:59:59.600000 UTC (MUST be included)
    $order1Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order1Id,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/var-bnd-1',
        'order_name' => '#var-bnd-1',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-21 03:59:59.600000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $line1Id = (string) Str::uuid();
    DB::table('order_line_items')->insert([
        'id' => $line1Id,
        'shop_id' => $this->shopId,
        'order_id' => $order1Id,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/var-bnd-line-1',
        'title_snapshot' => 'Standard',
        'quantity' => 2,
        'original_unit_price' => '100.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Refund on boundary: 2026-05-20 23:59:59.900000 EDT -> 2026-05-21 03:59:59.900000 UTC (MUST be included)
    DB::table('refund_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $order1Id,
        'line_item_id' => $line1Id,
        'shopify_refund_gid' => 'gid://shopify/Refund/var-bnd-ref-1',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/var-bnd-ref-line-1',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '100.000000',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-21 03:59:59.900000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Next-day midnight order: 2026-05-21 00:00:00.000000 EDT -> 2026-05-21 04:00:00.000000 UTC (MUST be excluded)
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order2Id,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/var-bnd-2',
        'order_name' => '#var-bnd-2',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-21 04:00:00.000000+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $order2Id,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/var-bnd-line-2',
        'title_snapshot' => 'Standard',
        'quantity' => 5,
        'original_unit_price' => '100.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sDetailGet("/api/v1/products/{$productId}/variants/analytics", [
        'from' => '2026-05-11',
        'to' => '2026-05-20',
    ]);

    $response->assertOk();
    $variants = $response->json('data.variants');
    expect($variants)->toHaveCount(1)
        ->and($variants[0]['title'])->toBe('Standard')
        ->and($variants[0]['gross_sales'])->toBe('200.000000')
        ->and($variants[0]['refunds'])->toBe('100.000000')
        ->and($variants[0]['net_sales'])->toBe('100.000000')
        ->and($variants[0]['units_sold'])->toBe(1);
});
