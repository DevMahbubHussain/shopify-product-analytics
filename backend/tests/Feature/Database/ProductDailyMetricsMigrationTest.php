<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('product_daily_metrics table has expected schema definition', function (): void {
    expect(Schema::hasTable('product_daily_metrics'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'product_id',
        'metric_date',
        'currency_code',
        'gross_sales',
        'discounts',
        'refunds',
        'net_sales',
        'units_sold',
        'order_count',
        'calculated_at',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('product_daily_metrics', $column))->toBeTrue("Missing expected column [{$column}] on product_daily_metrics table.");
    }
});

test('product_daily_metrics enforces compound unique constraint on (shop_id, product_id, metric_date, currency_code)', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-metrics.myshopify.com',
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
        'shopify_product_gid' => 'gid://shopify/Product/1234',
        'title' => 'Sneakers Pro',
        'handle' => 'sneakers-pro',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $metricDate = '2026-11-18';
    $currency = 'USD';

    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => $productId,
        'metric_date' => $metricDate,
        'currency_code' => $currency,
        'gross_sales' => '100.000000',
        'discounts' => '10.000000',
        'refunds' => '0.000000',
        'net_sales' => '90.000000',
        'units_sold' => 2,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Inserting exact same bucket must fail due to compound unique constraint
    expect(fn () => DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => $productId,
        'metric_date' => $metricDate,
        'currency_code' => $currency,
        'gross_sales' => '50.000000',
        'discounts' => '0.000000',
        'refunds' => '0.000000',
        'net_sales' => '50.000000',
        'units_sold' => 1,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('product_daily_metrics allows distinct currencies or dates for same product', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-multi.myshopify.com',
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
        'shopify_product_gid' => 'gid://shopify/Product/12345',
        'title' => 'Sneakers Pro',
        'handle' => 'sneakers-pro',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Same product, different dates
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => $productId,
        'metric_date' => '2026-11-17',
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
    ]);

    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => $productId,
        'metric_date' => '2026-11-18',
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
    ]);

    expect(DB::table('product_daily_metrics')->where('product_id', $productId)->count())->toBe(2);
});
