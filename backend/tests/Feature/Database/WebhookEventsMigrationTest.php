<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('webhook_events table has expected schema definition', function (): void {
    expect(Schema::hasTable('webhook_events'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'webhook_id',
        'topic',
        'payload_hash',
        'status',
        'received_at',
        'processed_at',
        'error_summary',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('webhook_events', $column))->toBeTrue("Missing expected column [{$column}] on webhook_events table.");
    }
});

test('webhook_events enforces compound unique constraint on (shop_id, webhook_id) for idempotency', function (): void {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'store-wh.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $webhookId = 'wh_delivery_abc123';

    DB::table('webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'webhook_id' => $webhookId,
        'topic' => 'orders/create',
        'payload_hash' => hash('sha256', '{"test":1}'),
        'status' => 'received',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Duplicate webhook delivery on same shop must fail
    expect(fn () => DB::table('webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'webhook_id' => $webhookId,
        'topic' => 'orders/create',
        'payload_hash' => hash('sha256', '{"test":1}'),
        'status' => 'received',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('webhook_events enforces foreign key on shop_id', function (): void {
    expect(fn () => DB::table('webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => (string) Str::uuid(), // non-existent shop
        'webhook_id' => 'wh_orphan',
        'topic' => 'orders/create',
        'payload_hash' => hash('sha256', '{"test":1}'),
        'status' => 'received',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
