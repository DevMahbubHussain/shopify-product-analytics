<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('order_line_items table has expected schema definition', function (): void {
    expect(Schema::hasTable('order_line_items'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'order_id',
        'product_id',
        'variant_id',
        'shopify_line_item_gid',
        'title_snapshot',
        'sku_snapshot',
        'quantity',
        'original_unit_price',
        'discount_amount',
        'currency_code',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('order_line_items', $column))->toBeTrue("Missing expected column [{$column}] on order_line_items table.");
    }
});

test('order_line_items enforces compound unique constraint on (shop_id, shopify_line_item_gid)', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store1.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/100',
        'order_name' => '#100',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lineGid = 'gid://shopify/LineItem/901';

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'shopify_line_item_gid' => $lineGid,
        'title_snapshot' => 'Classic Tee',
        'quantity' => 2,
        'original_unit_price' => '25.000000',
        'discount_amount' => '5.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate line item GID on same shop must fail
    expect(fn () => DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'shopify_line_item_gid' => $lineGid,
        'title_snapshot' => 'Classic Tee Duplicate',
        'quantity' => 1,
        'original_unit_price' => '25.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('order_line_items enforces foreign key on order_id', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-lines.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => (string) Str::uuid(), // non-existent order
        'shopify_line_item_gid' => 'gid://shopify/LineItem/999',
        'title_snapshot' => 'Orphan Line',
        'quantity' => 1,
        'original_unit_price' => '10.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
