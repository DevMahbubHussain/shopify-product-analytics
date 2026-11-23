<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('outbox-concurrency.myshopify.com', 'America/New_York');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], 'outbox-concurrency.myshopify.com')->assertOk();
});

it('only marks the specific outbox event as published without affecting concurrent pending events for the same shop', function (): void {
    // 1. Manually insert a concurrent pending outbox record for the same shop and same event class
    // simulating Request B whose event has been written to the outbox but not yet published to Redis.
    $concurrentOutboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $concurrentOutboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => ['concurrent-order-uuid'],
            'affected_buckets' => [],
        ], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'dispatched_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Execute Request A (an order ingestion request through the full S2S pipeline)
    $order = orderPayload(1, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T15:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, ['quantity' => 1, 'original_unit_price' => '40.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], 'outbox-concurrency.myshopify.com')->assertOk();

    // 3. Request A's outbox record MUST be marked published
    $publishedCount = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->where('status', 'published')
        ->count();

    expect($publishedCount)->toBe(1);

    // 4. CRITICAL: The concurrent pending record (Request B) MUST remain strictly pending!
    $concurrentRow = DB::table('outbox_events')
        ->where('id', $concurrentOutboxId)
        ->first();

    expect($concurrentRow)->not->toBeNull()
        ->and($concurrentRow->status)->toBe('pending')
        ->and($concurrentRow->dispatched_at)->toBeNull();
});
