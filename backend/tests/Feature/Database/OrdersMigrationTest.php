<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('orders table has expected schema definition and avoids protected customer data', function (): void {
    expect(Schema::hasTable('orders'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'shopify_order_gid',
        'order_name',
        'financial_status',
        'fulfillment_status',
        'currency_code',
        'processed_at',
        'cancelled_at',
        'shopify_updated_at',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('orders', $column))->toBeTrue("Missing expected column [{$column}] on orders table.");
    }

    // Verify PCD (Protected Customer Data) prohibition per constraint C5
    $forbiddenColumns = ['customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'billing_address', 'email', 'phone'];
    foreach ($forbiddenColumns as $forbidden) {
        expect(Schema::hasColumn('orders', $forbidden))->toBeFalse("Protected Customer Data column [{$forbidden}] is forbidden.");
    }
});

test('orders enforces compound unique constraint on (shop_id, shopify_order_gid)', function (): void {
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

    $orderGid = 'gid://shopify/Order/5001';

    DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'shopify_order_gid' => $orderGid,
        'order_name' => '#1001',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate order GID on same shop must fail
    expect(fn () => DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'shopify_order_gid' => $orderGid,
        'order_name' => '#1001-dup',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('orders allows same order gid across different shops', function (): void {
    $shop1 = (string) Str::uuid();
    $shop2 = (string) Str::uuid();
    $orderGid = 'gid://shopify/Order/5001';

    foreach ([$shop1 => 'storeA.myshopify.com', $shop2 => 'storeB.myshopify.com'] as $id => $domain) {
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

    DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shop1,
        'shopify_order_gid' => $orderGid,
        'order_name' => '#1001',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shop2,
        'shopify_order_gid' => $orderGid,
        'order_name' => '#1001',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('orders')->where('shopify_order_gid', $orderGid)->count())->toBe(2);
});

test('orders enforces foreign key on shop_id', function (): void {
    expect(fn () => DB::table('orders')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => (string) Str::uuid(), // non-existent shop
        'shopify_order_gid' => 'gid://shopify/Order/9999',
        'order_name' => '#9999',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
