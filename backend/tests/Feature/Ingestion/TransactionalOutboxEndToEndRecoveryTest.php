<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Analytics\Services\StoreTimezoneService;
use App\Events\OrdersIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use App\Listeners\QueueMetricAggregationsFromOrders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('e2e-recovery.myshopify.com', 'UTC');
    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], 'e2e-recovery.myshopify.com')->assertOk();
});

it('processes full ingestion through domain events to queued metrics aggregation and precomputes aggregates', function (): void {
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $order = orderPayload(1, [
        'processed_at' => '2026-05-15T14:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'quantity' => 3,
                'original_unit_price' => '25.00',
                'discount_amount' => '5.00',
            ]),
        ],
    ]);

    // 1. Send S2S order ingestion request without mocking events
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], 'e2e-recovery.myshopify.com')
        ->assertOk()
        ->assertJson(['written' => 1]);

    // 2. Verify outbox record was created and immediately marked published on fast-path
    $outbox = DB::table('outbox_events')->where('shop_id', $this->shopId)->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->status)->toBe('published')
        ->and($outbox->dispatched_at)->not->toBeNull();

    // 3. Verify real event listener dispatched AggregateProductDailyMetricsJob
    $product = DB::table('products')->where('shop_id', $this->shopId)->first();

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($product): bool {
        return $job->shopId === $this->shopId
            && $job->productId === $product->id
            && $job->metricDate === '2026-05-15';
    });

    // 4. Execute the aggregation job directly and verify daily aggregates in PostgreSQL
    $job = new AggregateProductDailyMetricsJob($this->shopId, $product->id, '2026-05-15');
    $job->handle(app(StoreTimezoneService::class));

    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $product->id)
        ->first();

    // 3 units * $25.00 = $75.00 Gross Sales, $5.00 discount = $70.00 Net Sales
    expect($metric)->not->toBeNull()
        ->and($metric->gross_sales)->toBe('75.000000')
        ->and($metric->net_sales)->toBe('70.000000')
        ->and((int) $metric->units_sold)->toBe(3)
        ->and((int) $metric->order_count)->toBe(1);
});

it('recovers stranded pending outbox records from simulated process crashes and executes aggregation', function (): void {
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    $product = DB::table('products')->where('shop_id', $this->shopId)->first();
    $orderId = (string) Str::uuid();

    // 1. Simulate database commit having succeeded but process crashed before Redis dispatch:
    // Outbox record remains 'pending' with created_at older than 30 seconds
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/crashed_99',
        'order_name' => '#CRASH-99',
        'financial_status' => 'paid',
        'currency_code' => 'USD',
        'processed_at' => CarbonImmutable::parse('2026-05-15T10:00:00Z'),
        'shopify_updated_at' => CarbonImmutable::parse('2026-05-15T10:00:00Z'),
        'created_at' => CarbonImmutable::now()->subMinutes(2),
        'updated_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $orderId,
        'product_id' => $product->id,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/crashed_line_99',
        'title_snapshot' => 'Crashed Product Item',
        'quantity' => 2,
        'original_unit_price' => '40.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => CarbonImmutable::now()->subMinutes(2),
        'updated_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    $strandedOutboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $strandedOutboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [$orderId],
            'affected_buckets' => [
                ['product_id' => $product->id, 'metric_date' => '2026-05-15'],
            ],
        ], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(2),
        'updated_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    // 2. Run Artisan recovery sweeper
    $this->artisan('outbox:publish --age=30')
        ->expectsOutputToContain('Published 1 stranded outbox events.')
        ->assertSuccessful();

    // 3. Verify outbox record transitioned to published
    $outbox = DB::table('outbox_events')->where('id', $strandedOutboxId)->first();
    expect($outbox->status)->toBe('published')
        ->and($outbox->dispatched_at)->not->toBeNull();

    // 4. Verify queue job was dispatched
    Queue::assertPushed(AggregateProductDailyMetricsJob::class);

    // 5. Execute queue job and verify metrics
    $job = new AggregateProductDailyMetricsJob($this->shopId, $product->id, '2026-05-15');
    $job->handle(app(StoreTimezoneService::class));

    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $product->id)
        ->first();

    expect($metric->gross_sales)->toBe('80.000000')
        ->and((int) $metric->units_sold)->toBe(2);
});

it('guarantees duplicate replay idempotency when recovery and queue jobs execute multiple times', function (): void {
    $product = DB::table('products')->where('shop_id', $this->shopId)->first();
    $orderId = (string) Str::uuid();

    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/replay_100',
        'order_name' => '#REPLAY-100',
        'financial_status' => 'paid',
        'currency_code' => 'USD',
        'processed_at' => CarbonImmutable::parse('2026-05-15T10:00:00Z'),
        'shopify_updated_at' => CarbonImmutable::parse('2026-05-15T10:00:00Z'),
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shopId,
        'order_id' => $orderId,
        'product_id' => $product->id,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/replay_line_100',
        'title_snapshot' => 'Replay Line Item',
        'quantity' => 4,
        'original_unit_price' => '15.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    $outboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [$orderId],
            'affected_buckets' => [
                ['product_id' => $product->id, 'metric_date' => '2026-05-15'],
            ],
        ], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    $timezoneService = app(StoreTimezoneService::class);

    // Run 1: First recovery run and job execution
    $this->artisan('outbox:publish --age=30')->assertSuccessful();
    $job1 = new AggregateProductDailyMetricsJob($this->shopId, $product->id, '2026-05-15');
    $job1->handle($timezoneService);

    $metricRun1 = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $product->id)
        ->first();

    expect($metricRun1->gross_sales)->toBe('60.000000')
        ->and((int) $metricRun1->units_sold)->toBe(4)
        ->and((int) $metricRun1->order_count)->toBe(1);

    // Run 2: Replay recovery command (must find 0 events because outbox is already published)
    $this->artisan('outbox:publish --age=30')
        ->expectsOutputToContain('No stranded outbox events found.')
        ->assertSuccessful();

    // Replay queue job execution (simulating network duplicate queue delivery)
    $job2 = new AggregateProductDailyMetricsJob($this->shopId, $product->id, '2026-05-15');
    $job2->handle($timezoneService);

    // Verify metrics remain strictly identical (not doubled to $120 or 8 units)
    $metricRows = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $product->id)
        ->get();

    expect($metricRows)->toHaveCount(1)
        ->and($metricRows[0]->gross_sales)->toBe('60.000000')
        ->and((int) $metricRows[0]->units_sold)->toBe(4)
        ->and((int) $metricRows[0]->order_count)->toBe(1);
});
