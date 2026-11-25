<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopDomain = 'bucket-concurrency.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'UTC');

    s2sPost('/internal/v1/ingestion/products', [
        'products' => [
            productPayload(1),
            productPayload(2),
            productPayload(3),
        ],
    ], $this->shopDomain)->assertOk();

    $this->productId1 = DB::table('products')->where('shop_id', $this->shopId)->where('shopify_product_gid', 'gid://shopify/Product/1')->value('id');
    $this->productId2 = DB::table('products')->where('shop_id', $this->shopId)->where('shopify_product_gid', 'gid://shopify/Product/2')->value('id');
    $this->productId3 = DB::table('products')->where('shop_id', $this->shopId)->where('shopify_product_gid', 'gid://shopify/Product/3')->value('id');
});

it('captures both old and new buckets across line item removals, additions, and date shifts', function (): void {
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    // 1. Initial order on 2026-05-10 with Product 1 and Product 2
    $initialOrder = orderPayload(1, [
        'processed_at' => '2026-05-10T12:00:00Z',
        'shopify_updated_at' => '2026-05-10T12:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, ['quantity' => 2, 'original_unit_price' => '20.00']),
            lineItemPayload(1, 2, 2, ['quantity' => 1, 'original_unit_price' => '50.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$initialOrder]], $this->shopDomain)
        ->assertOk()
        ->assertJson(['written' => 1]);

    // Verify initial buckets: (Product 1, 2026-05-10) and (Product 2, 2026-05-10)
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId1 && $job->metricDate === '2026-05-10'
    );
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId2 && $job->metricDate === '2026-05-10'
    );

    // 2. Updated order:
    // - processed_at shifts to 2026-05-15
    // - Product 2 is removed from the order
    // - Product 3 is added to the order
    // - Product 1 remains with updated quantity
    $updatedOrder = orderPayload(1, [
        'processed_at' => '2026-05-15T14:00:00Z',
        'shopify_updated_at' => '2026-05-15T14:30:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, ['quantity' => 4, 'original_unit_price' => '20.00']),
            lineItemPayload(1, 3, 3, ['quantity' => 1, 'original_unit_price' => '100.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$updatedOrder]], $this->shopDomain)
        ->assertOk()
        ->assertJson(['written' => 1]);

    // MUST dispatch recalculation for:
    // OLD buckets:
    // - Product 1 on 2026-05-10 (to recalculate now that order moved)
    // - Product 2 on 2026-05-10 (to recalculate now that line item was pruned)
    // NEW buckets:
    // - Product 1 on 2026-05-15 (new date)
    // - Product 3 on 2026-05-15 (new line item)
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId1 && $job->metricDate === '2026-05-10'
    );
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId2 && $job->metricDate === '2026-05-10'
    );
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId1 && $job->metricDate === '2026-05-15'
    );
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId3 && $job->metricDate === '2026-05-15'
    );

    // Verify latest outbox record contains the complete unified affected buckets
    $latestOutbox = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->first();

    $payload = json_decode($latestOutbox->payload, true);
    $affected = $payload['affected_buckets'];

    $keys = array_map(fn ($b) => "{$b['product_id']}:{$b['metric_date']}", $affected);
    sort($keys);

    expect($keys)->toContain("{$this->productId1}:2026-05-10")
        ->and($keys)->toContain("{$this->productId2}:2026-05-10")
        ->and($keys)->toContain("{$this->productId1}:2026-05-15")
        ->and($keys)->toContain("{$this->productId3}:2026-05-15");
});

it('captures refund pre-update and post-update buckets across multiple line items', function (): void {
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    // 1. Create order with line item
    $order = orderPayload(10, [
        'processed_at' => '2026-06-01T10:00:00Z',
        'shopify_updated_at' => '2026-06-01T10:00:00Z',
        'line_items' => [
            lineItemPayload(10, 1, 1, ['quantity' => 5, 'original_unit_price' => '20.00']),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], $this->shopDomain)->assertOk();

    // 2. Ingest refund for the line item on 2026-06-03
    $refundPayload = [
        'refund_lines' => [
            refundLinePayload(101, 10, [
                'quantity' => 2,
                'subtotal' => '40.00',
                'processed_at' => '2026-06-03T15:00:00Z',
            ]),
        ],
    ];

    s2sPost('/internal/v1/ingestion/refunds', $refundPayload, $this->shopDomain)
        ->assertOk()
        ->assertJson(['written' => 1]);

    // Assert refund dispatched aggregation for (Product 1, 2026-06-03)
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId1 && $job->metricDate === '2026-06-03'
    );
});
