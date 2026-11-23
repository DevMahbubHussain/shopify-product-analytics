<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\RefundsIngested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1), orderPayload(2)]])->assertOk();
});

it('rejects out-of-order stale refund updates without overwriting data or emitting outbox events', function (): void {
    Event::fake([RefundsIngested::class]);

    // Initial newer refund
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '50.00',
                'quantity' => 2,
                'processed_at' => '2026-11-05T12:00:00Z',
                'shopify_updated_at' => '2026-11-05T14:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    Event::assertDispatched(RefundsIngested::class, 1);

    $initialOutboxCount = DB::table('outbox_events')->where('shop_id', $this->shopId)->count();

    // Stale out-of-order update arriving later with older shopify_updated_at
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '20.00',
                'quantity' => 1,
                'processed_at' => '2026-11-05T12:00:00Z',
                'shopify_updated_at' => '2026-11-05T13:00:00Z', // 1 hour older than stored 14:00
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    // Stored data must NOT be overwritten
    $refund = DB::table('refund_line_items')
        ->where('shop_id', $this->shopId)
        ->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')
        ->first();

    expect($refund->refunded_subtotal)->toBe('50.000000')
        ->and($refund->refunded_quantity)->toBe(2)
        ->and(CarbonImmutable::parse($refund->shopify_updated_at)->toISOString())->toBe('2026-11-05T14:00:00.000000Z');

    // No new outbox event emitted for stale dropped update
    expect(DB::table('outbox_events')->where('shop_id', $this->shopId)->count())->toBe($initialOutboxCount);
    Event::assertDispatched(RefundsIngested::class, 1);
});

it('applies newer refund line updates and emits outbox events for recalculation', function (): void {
    Event::fake([RefundsIngested::class]);

    // Initial state
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '50.00',
                'quantity' => 2,
                'shopify_updated_at' => '2026-11-05T12:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    // Newer update arriving
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '75.00',
                'quantity' => 3,
                'shopify_updated_at' => '2026-11-05T13:00:00Z', // newer
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    $refund = DB::table('refund_line_items')
        ->where('shop_id', $this->shopId)
        ->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')
        ->first();

    expect($refund->refunded_subtotal)->toBe('75.000000')
        ->and($refund->refunded_quantity)->toBe(3)
        ->and(CarbonImmutable::parse($refund->shopify_updated_at)->toISOString())->toBe('2026-11-05T13:00:00.000000Z');

    Event::assertDispatched(RefundsIngested::class, 2);
});

it('falls back to processed_at as monotonic freshness when shopify_updated_at is omitted', function (): void {
    // Initial payload without explicit shopify_updated_at
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '50.00',
                'processed_at' => '2026-11-05T12:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    // Stale payload without explicit shopify_updated_at but older processed_at
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '20.00',
                'processed_at' => '2026-11-05T10:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    $refund = DB::table('refund_line_items')
        ->where('shop_id', $this->shopId)
        ->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')
        ->first();

    expect($refund->refunded_subtotal)->toBe('50.000000');
});

it('handles mixed batches containing both fresh and stale refund lines', function (): void {
    Event::fake([RefundsIngested::class]);

    // Initial state for line 1 and line 2
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, ['subtotal' => '30.00', 'shopify_updated_at' => '2026-11-05T12:00:00Z']),
            refundLinePayload(2, 2, ['subtotal' => '40.00', 'shopify_updated_at' => '2026-11-05T12:00:00Z']),
        ],
    ])->assertOk()->assertExactJson(['received' => 2, 'written' => 2, 'skipped_stale' => 0]);

    // Mixed batch: line 1 is stale (11:00 < 12:00), line 2 is fresh (13:00 > 12:00)
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, ['subtotal' => '10.00', 'shopify_updated_at' => '2026-11-05T11:00:00Z']),
            refundLinePayload(2, 2, ['subtotal' => '90.00', 'shopify_updated_at' => '2026-11-05T13:00:00Z']),
        ],
    ])->assertOk()->assertExactJson(['received' => 2, 'written' => 1, 'skipped_stale' => 1]);

    $line1 = DB::table('refund_line_items')->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')->first();
    $line2 = DB::table('refund_line_items')->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/2')->first();

    // Line 1 was not overwritten
    expect($line1->refunded_subtotal)->toBe('30.000000')
        // Line 2 was updated
        ->and($line2->refunded_subtotal)->toBe('90.000000');
});
