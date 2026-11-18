<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('subscriptions table has expected schema definition', function (): void {
    expect(Schema::hasTable('subscriptions'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'shopify_subscription_gid',
        'plan_key',
        'status',
        'trial_ends_at',
        'current_period_end',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('subscriptions', $column))->toBeTrue("Missing expected column [{$column}] on subscriptions table.");
    }
});

test('subscriptions enforces foreign key on shop_id', function (): void {
    expect(fn () => DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => (string) Str::uuid(), // non-existent shop
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/12345',
        'plan_key' => 'growth',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
