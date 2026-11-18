<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('shops table has expected schema definition', function (): void {
    expect(Schema::hasTable('shops'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_domain',
        'shopify_shop_gid',
        'currency_code',
        'timezone',
        'status',
        'installed_at',
        'uninstalled_at',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('shops', $column))->toBeTrue("Missing expected column [{$column}] on shops table.");
    }
});

test('shops table enforces unique constraint on shop_domain', function (): void {
    $shopId1 = (string) Str::uuid();
    $shopId2 = (string) Str::uuid();
    $domain = 'merchant-test.myshopify.com';

    DB::table('shops')->insert([
        'id' => $shopId1,
        'shop_domain' => $domain,
        'shopify_shop_gid' => 'gid://shopify/Shop/12345678',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('shops')->insert([
        'id' => $shopId2,
        'shop_domain' => $domain,
        'shopify_shop_gid' => 'gid://shopify/Shop/87654321',
        'currency_code' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});
