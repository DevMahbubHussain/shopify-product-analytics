<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('refund_line_items table has expected schema definition', function (): void {
    expect(Schema::hasTable('refund_line_items'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'order_id',
        'line_item_id',
        'shopify_refund_gid',
        'shopify_refund_line_item_gid',
        'refunded_quantity',
        'refunded_subtotal',
        'currency_code',
        'processed_at',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('refund_line_items', $column))->toBeTrue("Missing expected column [{$column}] on refund_line_items table.");
    }
});

test('refund_line_items enforces foreign key on order_id and shop_id', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-refunds.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('refund_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => (string) Str::uuid(), // non-existent order
        'shopify_refund_gid' => 'gid://shopify/Refund/101',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/101',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '25.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
