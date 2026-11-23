<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('snapshot-concurrency.myshopify.com', 'UTC');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], 'snapshot-concurrency.myshopify.com')->assertOk();
});

it('rejects stale order updates without overwriting newer order and line item snapshots', function (): void {
    $newerTimestamp = '2026-05-15T12:00:00Z';
    $staleTimestamp = '2026-05-15T11:00:00Z';

    // 1. Ingest newer version of the order first
    $newerOrder = orderPayload(1, [
        'shopify_updated_at' => $newerTimestamp,
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'title' => 'Newer Title Snapshot',
                'sku' => 'SKU-NEWER',
                'original_unit_price' => '50.00',
            ]),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$newerOrder]], 'snapshot-concurrency.myshopify.com')
        ->assertOk()
        ->assertJson(['written' => 1]);

    // 2. Attempt to ingest a stale update for the same order arriving out of order
    $staleOrder = orderPayload(1, [
        'shopify_updated_at' => $staleTimestamp,
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'title' => 'Stale Title Snapshot',
                'sku' => 'SKU-STALE',
                'original_unit_price' => '40.00',
            ]),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$staleOrder]], 'snapshot-concurrency.myshopify.com')
        ->assertOk()
        ->assertJson(['written' => 0]);

    // 3. Verify database preserves the newer snapshot untouched
    $order = DB::table('orders')->where('shop_id', $this->shopId)->first();
    $line = DB::table('order_line_items')->where('shop_id', $this->shopId)->first();

    expect(CarbonImmutable::parse($order->shopify_updated_at)->toISOString())->toBe(CarbonImmutable::parse($newerTimestamp)->toISOString())
        ->and($line->title_snapshot)->toBe('Newer Title Snapshot')
        ->and($line->sku_snapshot)->toBe('SKU-NEWER')
        ->and($line->original_unit_price)->toBe('50.000000');
});

it('applies newer order updates updating line item snapshots accurately', function (): void {
    $time1 = '2026-05-15T12:00:00Z';
    $time2 = '2026-05-15T12:30:00Z';

    // 1. Initial order state
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'shopify_updated_at' => $time1,
                'line_items' => [
                    lineItemPayload(1, 1, 1, [
                        'title' => 'Initial Title',
                        'original_unit_price' => '30.00',
                    ]),
                ],
            ]),
        ],
    ], 'snapshot-concurrency.myshopify.com')->assertOk()->assertJson(['written' => 1]);

    // 2. Newer update modifying line item snapshot
    s2sPost('/internal/v1/ingestion/orders', [
        'orders' => [
            orderPayload(1, [
                'shopify_updated_at' => $time2,
                'line_items' => [
                    lineItemPayload(1, 1, 1, [
                        'title' => 'Updated Title Snapshot',
                        'original_unit_price' => '35.00',
                    ]),
                ],
            ]),
        ],
    ], 'snapshot-concurrency.myshopify.com')->assertOk()->assertJson(['written' => 1]);

    $line = DB::table('order_line_items')->where('shop_id', $this->shopId)->first();

    expect($line->title_snapshot)->toBe('Updated Title Snapshot')
        ->and($line->original_unit_price)->toBe('35.000000');
});

it('processes reversed order batches with deterministic sorting preventing deadlocks', function (): void {
    // Two batches with reverse order sequences
    $batchA = [
        orderPayload(1, ['shopify_updated_at' => '2026-05-15T12:00:00Z']),
        orderPayload(2, ['shopify_updated_at' => '2026-05-15T12:00:00Z']),
    ];

    $batchB = [
        orderPayload(2, ['shopify_updated_at' => '2026-05-15T12:05:00Z']),
        orderPayload(1, ['shopify_updated_at' => '2026-05-15T12:05:00Z']),
    ];

    s2sPost('/internal/v1/ingestion/orders', ['orders' => $batchA], 'snapshot-concurrency.myshopify.com')
        ->assertOk()
        ->assertJson(['written' => 2]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => $batchB], 'snapshot-concurrency.myshopify.com')
        ->assertOk()
        ->assertJson(['written' => 2]);

    expect(DB::table('orders')->where('shop_id', $this->shopId)->count())->toBe(2);
});
