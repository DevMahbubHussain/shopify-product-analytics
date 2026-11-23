<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Analytics\Services\StoreTimezoneService;
use App\Events\RefundsIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    $this->product = DB::table('products')->where('shop_id', $this->shopId)->first();
    $this->timezoneService = app(StoreTimezoneService::class);

    // Seed base order
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'processed_at' => '2026-05-15T10:00:00Z',
                'shopify_updated_at' => '2026-05-15T10:00:00Z',
                'line_items' => [
                    lineItemPayload(1, 1, 1, [
                        'quantity' => 2,
                        'original_unit_price' => '50.000000',
                        'discount_amount' => '0.000000',
                    ]),
                ],
            ]),
        ],
    ])->assertOk();
});

it('rejects out-of-order refund updates preventing older data from overwriting newer records', function (): void {
    Event::fake([RefundsIngested::class]);

    // Initial newer refund (processed at 12:00, updated at 14:00)
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '60.00',
                'quantity' => 2,
                'processed_at' => '2026-05-15T12:00:00Z',
                'shopify_updated_at' => '2026-05-15T14:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    Event::assertDispatched(RefundsIngested::class, 1);
    $initialOutboxCount = DB::table('outbox_events')->where('shop_id', $this->shopId)->count();

    // Out-of-order webhook delivery arriving later with older shopify_updated_at (13:00)
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '20.00',
                'quantity' => 1,
                'processed_at' => '2026-05-15T12:00:00Z',
                'shopify_updated_at' => '2026-05-15T13:00:00Z', // 1 hour older
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    // Newer database row must NOT be overwritten
    $refund = DB::table('refund_line_items')
        ->where('shop_id', $this->shopId)
        ->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')
        ->first();

    expect($refund->refunded_subtotal)->toBe('60.000000')
        ->and($refund->refunded_quantity)->toBe(2)
        ->and(CarbonImmutable::parse($refund->shopify_updated_at)->toISOString())->toBe('2026-05-15T14:00:00.000000Z');

    // No outbox events emitted for dropped stale payload
    expect(DB::table('outbox_events')->where('shop_id', $this->shopId)->count())->toBe($initialOutboxCount);
    Event::assertDispatched(RefundsIngested::class, 1);
});

it('guarantees duplicate refund webhook deliveries never duplicate rows or double-count refunded metrics', function (): void {
    // Initial daily aggregation before refund: 2 units sold, $100 gross sales, $100 net sales
    $job = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $job->handle($this->timezoneService);

    $metricBefore = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect((int) $metricBefore->units_sold)->toBe(2)
        ->and($metricBefore->gross_sales)->toBe('100.000000')
        ->and($metricBefore->refunds)->toBe('0.000000')
        ->and($metricBefore->net_sales)->toBe('100.000000');

    $refundPayload = refundLinePayload(1, 1, [
        'subtotal' => '50.00',
        'quantity' => 1,
        'processed_at' => '2026-05-15T12:00:00Z',
        'shopify_updated_at' => '2026-05-15T12:00:00Z',
    ]);

    // Delivery 1 (initial refund delivery)
    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundPayload]])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    // Recalculate daily metrics
    $job->handle($this->timezoneService);

    $metricAfterRefund = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    // 2 units - 1 refunded = 1 unit sold; $100 gross - $50 refund = $50 net sales
    expect((int) $metricAfterRefund->units_sold)->toBe(1)
        ->and($metricAfterRefund->gross_sales)->toBe('100.000000')
        ->and($metricAfterRefund->refunds)->toBe('50.000000')
        ->and($metricAfterRefund->net_sales)->toBe('50.000000');

    // Delivery 2 (Shopify webhook duplicate retry)
    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundPayload]])
        ->assertOk();

    // Delivery 3 (Another webhook retry)
    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$refundPayload]])
        ->assertOk();

    // Exactly 1 refund row in database (zero duplicate records)
    expect(DB::table('refund_line_items')->where('shop_id', $this->shopId)->count())->toBe(1);

    // Re-run aggregation job after duplicate webhook deliveries
    $job->handle($this->timezoneService);

    $metricAfterDuplicates = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    // Verified: Exactly identical metrics with ZERO double-counting!
    expect((int) $metricAfterDuplicates->units_sold)->toBe(1)
        ->and($metricAfterDuplicates->gross_sales)->toBe('100.000000')
        ->and($metricAfterDuplicates->refunds)->toBe('50.000000')
        ->and($metricAfterDuplicates->net_sales)->toBe('50.000000');
});

it('isolates mixed batches applying fresh refund lines while discarding stale lines', function (): void {
    // Seed order 2
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(2, [
                'processed_at' => '2026-05-15T10:00:00Z',
                'shopify_updated_at' => '2026-05-15T10:00:00Z',
            ]),
        ],
    ])->assertOk();

    // Initial state for refund line 1 (order 1) and refund line 2 (order 2)
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, ['subtotal' => '30.00', 'shopify_updated_at' => '2026-05-15T12:00:00Z']),
            refundLinePayload(2, 2, ['subtotal' => '40.00', 'shopify_updated_at' => '2026-05-15T12:00:00Z']),
        ],
    ])->assertOk()->assertExactJson(['received' => 2, 'written' => 2, 'skipped_stale' => 0]);

    Event::fake([RefundsIngested::class]);

    // Mixed batch:
    // Line 1 is stale (11:00 < stored 12:00)
    // Line 2 is fresh (13:00 > stored 12:00)
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, ['subtotal' => '10.00', 'shopify_updated_at' => '2026-05-15T11:00:00Z']),
            refundLinePayload(2, 2, ['subtotal' => '90.00', 'shopify_updated_at' => '2026-05-15T13:00:00Z']),
        ],
    ])->assertOk()->assertExactJson(['received' => 2, 'written' => 1, 'skipped_stale' => 1]);

    $line1 = DB::table('refund_line_items')->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')->first();
    $line2 = DB::table('refund_line_items')->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/2')->first();

    // Line 1 preserved without overwrite
    expect($line1->refunded_subtotal)->toBe('30.000000')
        // Line 2 updated
        ->and($line2->refunded_subtotal)->toBe('90.000000');

    // Only order 2 (written line) was dispatched in the outbox event
    $order2Id = DB::table('orders')->where('shopify_order_gid', 'gid://shopify/Order/2')->value('id');
    Event::assertDispatched(RefundsIngested::class, fn (RefundsIngested $e): bool => $e->orderIds === [$order2Id]);
});

it('enforces monotonic ordering using processed_at when shopify_updated_at is absent', function (): void {
    // Initial payload without shopify_updated_at
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '50.00',
                'processed_at' => '2026-05-15T12:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    // Stale payload without shopify_updated_at but older processed_at
    s2sPost('/internal/v1/ingestion/refunds', [
        'refund_lines' => [
            refundLinePayload(1, 1, [
                'subtotal' => '20.00',
                'processed_at' => '2026-05-15T10:00:00Z',
            ]),
        ],
    ])->assertOk()->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    $refund = DB::table('refund_line_items')
        ->where('shop_id', $this->shopId)
        ->where('shopify_refund_line_item_gid', 'gid://shopify/RefundLineItem/1')
        ->first();

    // Older data did not overwrite
    expect($refund->refunded_subtotal)->toBe('50.000000');
});
