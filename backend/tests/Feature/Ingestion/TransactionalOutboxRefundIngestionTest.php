<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\RefundsIngested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopDomain = 'outbox-refund.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'America/New_York');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], $this->shopDomain)->assertOk();

    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'processed_at' => '2026-05-10T12:00:00Z',
                'shopify_updated_at' => '2026-05-10T12:00:00Z',
                'line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 2, 'original_unit_price' => '50.00'])],
            ]),
        ],
    ], $this->shopDomain)->assertOk();
});

it('atomically records an outbox event during refund batch ingestion', function () {
    $refundLine = refundLinePayload(1, 1, [
        'quantity' => 1,
        'subtotal' => '50.00',
        'processed_at' => '2026-05-15T15:00:00Z',
    ]);

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundLine]], $this->shopDomain)->assertOk();

    // Verify outbox_events table has the recorded RefundsIngested event
    $outboxRow = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->where('event_class', RefundsIngested::class)
        ->first();

    expect($outboxRow)->not->toBeNull()
        ->and($outboxRow->shop_id)->toBe($this->shopId);

    $payload = json_decode($outboxRow->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['order_ids'])->toHaveCount(1)
        ->and($payload['affected_buckets'])->toHaveCount(1)
        ->and($payload['affected_buckets'][0]['metric_date'])->toBe('2026-05-15');
});

it('rolls back outbox records if the refund transaction fails', function () {
    $initialOutboxCount = DB::table('outbox_events')->where('event_class', RefundsIngested::class)->count();

    // Malformed refund payload (referencing missing order)
    $invalidRefund = [
        'refund_gid' => 'gid://shopify/Refund/999999',
        'refund_line_item_gid' => 'gid://shopify/RefundLineItem/999999',
        'order_gid' => 'gid://shopify/Order/999999',
        'line_item_gid' => 'gid://shopify/LineItem/999999',
        'quantity' => 1,
        'subtotal' => '50.00',
        'currency_code' => 'USD',
        'processed_at' => '2026-05-15T15:00:00Z',
    ];

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$invalidRefund]], $this->shopDomain)->assertStatus(409);

    expect(DB::table('outbox_events')->where('event_class', RefundsIngested::class)->count())->toBe($initialOutboxCount);
});
