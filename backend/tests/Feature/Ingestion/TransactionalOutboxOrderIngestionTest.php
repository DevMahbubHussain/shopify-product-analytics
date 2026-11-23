<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopDomain = 'outbox-order.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'America/New_York');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(101)],
    ], $this->shopDomain)->assertOk();
});

it('atomically records an outbox event during order batch ingestion', function () {
    $order = orderPayload(201, [
        'processed_at' => '2026-05-15T15:00:00Z',
        'shopify_updated_at' => '2026-05-15T15:00:00Z',
        'line_items' => [lineItemPayload(301, 101, 101, ['quantity' => 1, 'original_unit_price' => '100.00'])],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], $this->shopDomain)->assertOk();

    // Verify outbox_events table has the recorded event
    $outboxRow = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->where('event_class', OrdersIngested::class)
        ->first();

    expect($outboxRow)->not->toBeNull()
        ->and($outboxRow->shop_id)->toBe($this->shopId);

    $payload = json_decode($outboxRow->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['order_ids'])->toHaveCount(1)
        ->and($payload['affected_buckets'])->toHaveCount(1)
        ->and($payload['affected_buckets'][0]['metric_date'])->toBe('2026-05-15');
});

it('rolls back outbox records if the order transaction fails', function () {
    $initialOutboxCount = DB::table('outbox_events')->count();

    // Malformed order payload causing SQL transaction error or validation rejection
    $invalidOrder = [
        'gid' => 'gid://shopify/Order/invalid',
        'name' => '#invalid',
        'currency' => 'INVALID_CURRENCY_TOOLONG', // Fails CHECK constraint on currency_code ~ '^[A-Z]{3}$'
        'processed_at' => '2026-05-15T15:00:00Z',
        'line_items' => [],
    ];

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$invalidOrder]], $this->shopDomain)->assertStatus(422);

    expect(DB::table('outbox_events')->count())->toBe($initialOutboxCount);
});
