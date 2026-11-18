<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('products table has expected schema definition', function (): void {
    expect(Schema::hasTable('products'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'shopify_product_gid',
        'title',
        'handle',
        'vendor',
        'product_type',
        'status',
        'image_url',
        'published_at',
        'shopify_updated_at',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('products', $column))->toBeTrue("Missing expected column [{$column}] on products table.");
    }
});

test('products table enforces compound unique constraint on (shop_id, shopify_product_gid)', function (): void {
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

    $productGid = 'gid://shopify/Product/1001';

    DB::table('products')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'shopify_product_gid' => $productGid,
        'title' => 'Sneakers',
        'handle' => 'sneakers',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Inserting same product GID for the SAME shop must fail
    expect(fn () => DB::table('products')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'shopify_product_gid' => $productGid,
        'title' => 'Sneakers Duplicate',
        'handle' => 'sneakers-duplicate',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('products table allows same shopify_product_gid across different shops', function (): void {
    $shop1 = (string) Str::uuid();
    $shop2 = (string) Str::uuid();
    $productGid = 'gid://shopify/Product/1001';

    foreach ([$shop1 => 's1.myshopify.com', $shop2 => 's2.myshopify.com'] as $id => $domain) {
        DB::table('shops')->insert([
            'id' => $id,
            'shop_domain' => $domain,
            'currency_code' => 'USD',
            'timezone' => 'UTC',
            'status' => 'active',
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('products')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shop1,
        'shopify_product_gid' => $productGid,
        'title' => 'Sneakers Store 1',
        'handle' => 'sneakers-1',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Inserting same product GID for DIFFERENT shop must succeed
    DB::table('products')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shop2,
        'shopify_product_gid' => $productGid,
        'title' => 'Sneakers Store 2',
        'handle' => 'sneakers-2',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('products')->where('shopify_product_gid', $productGid)->count())->toBe(2);
});

test('products table enforces foreign key on shop_id', function (): void {
    expect(fn () => DB::table('products')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => (string) Str::uuid(), // non-existent shop
        'shopify_product_gid' => 'gid://shopify/Product/9999',
        'title' => 'Orphan Product',
        'handle' => 'orphan-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
