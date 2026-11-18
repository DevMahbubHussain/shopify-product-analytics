<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('product_variants table has expected schema definition', function (): void {
    expect(Schema::hasTable('product_variants'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'product_id',
        'shopify_variant_gid',
        'title',
        'sku',
        'price',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('product_variants', $column))->toBeTrue("Missing expected column [{$column}] on product_variants table.");
    }
});

test('product_variants enforces compound unique constraint on (shop_id, shopify_variant_gid)', function (): void {
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

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/100',
        'title' => 'Shirt',
        'handle' => 'shirt',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantGid = 'gid://shopify/ProductVariant/200';

    DB::table('product_variants')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => $variantGid,
        'title' => 'Shirt - Large',
        'sku' => 'SHIRT-L',
        'price' => '29.990000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate variant GID on same shop must fail
    expect(fn () => DB::table('product_variants')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => $variantGid,
        'title' => 'Shirt - Large Duplicate',
        'sku' => 'SHIRT-L-DUP',
        'price' => '29.990000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('product_variants enforces foreign key on product_id', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-fk.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Non-existent product_id must throw QueryException
    expect(fn () => DB::table('product_variants')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'product_id' => (string) Str::uuid(),
        'shopify_variant_gid' => 'gid://shopify/ProductVariant/999',
        'title' => 'Orphan Variant',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});

test('product_variants enforces foreign key on shop_id', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-fk2.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/999',
        'title' => 'Valid Product',
        'handle' => 'valid-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Non-existent shop_id must throw QueryException
    expect(fn () => DB::table('product_variants')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => (string) Str::uuid(),
        'product_id' => $productId,
        'shopify_variant_gid' => 'gid://shopify/ProductVariant/999',
        'title' => 'Orphan Shop Variant',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
