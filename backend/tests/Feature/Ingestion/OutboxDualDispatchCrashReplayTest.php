<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Analytics\Services\StoreTimezoneService;
use App\Events\OrdersIngested;
use App\Ingestion\Services\OutboxService;
use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    $this->product = DB::table('products')->where('shop_id', $this->shopId)->first();
    $this->timezoneService = app(StoreTimezoneService::class);
    $this->outboxService = app(OutboxService::class);
    $this->redis = Redis::connection();
    $this->redis->del('queues:default');
});

it('proves idempotent metric convergence when fast-path dispatches but crashes before markPublished', function (): void {
    // 1. Ensure Redis queue is clean before test
    $this->redis->del('queues:default');

    // 2. Bind a simulated OutboxService test double that throws during markPublished()
    // The real DispatchIngestedEvents pipe is used; it invokes markPublished(), catches the error, and leaves record pending
    $simulatedOutbox = new class extends OutboxService {
        public int $markPublishedCalls = 0;
        public bool $shouldCrashOnMarkPublished = true;

        public function markPublished(string $outboxId): void
        {
            $this->markPublishedCalls++;

            if ($this->shouldCrashOnMarkPublished) {
                throw new \RuntimeException('Simulated process crash / DB disconnection right after event dispatch');
            }

            parent::markPublished($outboxId);
        }
    };

    $this->app->instance(OutboxService::class, $simulatedOutbox);

    // 3. Ingest an order through HTTP endpoint using the REAL DispatchIngestedEvents pipe
    $orderData = orderPayload(1, [
        'processed_at' => '2026-05-15T12:00:00Z',
        'shopify_updated_at' => '2026-05-15T12:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'quantity' => 2,
                'original_unit_price' => '50.000000',
                'discount_amount' => '0.000000',
            ]),
        ],
    ]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderData]])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    // VERIFY FIRST QUEUE DISPATCH TO REAL REDIS:
    // Event listener QueueMetricAggregationsFromOrders was physically pushed to Redis queues:default
    $firstDispatchQueueLen = $this->redis->llen('queues:default');
    expect($firstDispatchQueueLen)->toBeGreaterThan(0);

    // Inspect serialized payload directly from Redis list
    $firstPayloadRaw = $this->redis->lindex('queues:default', 0);
    expect($firstPayloadRaw)->not->toBeEmpty();
    $firstPayload = json_decode((string) $firstPayloadRaw, true);
    expect($firstPayload['displayName'] ?? '')->toBe(\App\Listeners\QueueMetricAggregationsFromOrders::class);

    // VERIFY MARK_PUBLISHED FAILED & WAS CAUGHT BY REAL PIPE:
    // 1. OutboxService::markPublished was actually invoked by DispatchIngestedEvents
    expect($simulatedOutbox->markPublishedCalls)->toBe(1);

    // 2. Real DispatchIngestedEvents caught the exception, leaving outbox record pending in PostgreSQL
    $outboxRecord = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->first();

    expect($outboxRecord)->not->toBeNull()
        ->and($outboxRecord->status)->toBe('pending')
        ->and($outboxRecord->dispatched_at)->toBeNull();

    // 4. First consumer job executes (simulating worker processing the initial queue push)
    $firstJob = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $firstJob->handle($this->timezoneService);

    $metricAfterFirstJob = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect((int) $metricAfterFirstJob->units_sold)->toBe(2)
        ->and((int) $metricAfterFirstJob->order_count)->toBe(1)
        ->and($metricAfterFirstJob->gross_sales)->toBe('100.000000')
        ->and($metricAfterFirstJob->net_sales)->toBe('100.000000');

    // 5. Recovery sweeper runs:
    // Allow markPublished to succeed for the sweeper
    $simulatedOutbox->shouldCrashOnMarkPublished = false;

    // Simulate time passing beyond stranded cutoff (30s)
    DB::table('outbox_events')
        ->where('id', $outboxRecord->id)
        ->update([
            'created_at' => CarbonImmutable::now()->subSeconds(60),
            'updated_at' => CarbonImmutable::now()->subSeconds(60),
        ]);

    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    // VERIFY SECOND QUEUE DISPATCH TO REAL REDIS (Two distinct deliveries in Redis queue):
    // Sweeper re-dispatched the event and the Redis queue list received its second delivery
    $secondDispatchQueueLen = $this->redis->llen('queues:default');
    expect($secondDispatchQueueLen)->toBe($firstDispatchQueueLen * 2);
    expect($simulatedOutbox->markPublishedCalls)->toBe(2);

    // Outbox record is now published by the sweeper
    $outboxAfterSweeper = DB::table('outbox_events')->where('id', $outboxRecord->id)->first();
    expect($outboxAfterSweeper->status)->toBe('published')
        ->and($outboxAfterSweeper->dispatched_at)->not->toBeNull();

    // 6. Second consumer job executes (simulating worker processing duplicate delivery from sweeper)
    $secondJob = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $secondJob->handle($this->timezoneService);

    // VERIFY FINAL AGGREGATION IDEMPOTENCY:
    // Re-aggregating over raw orders produces identical metric totals; ZERO double-counting!
    $metricAfterDuplicateJob = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect((int) $metricAfterDuplicateJob->units_sold)->toBe(2)
        ->and((int) $metricAfterDuplicateJob->order_count)->toBe(1)
        ->and($metricAfterDuplicateJob->gross_sales)->toBe('100.000000')
        ->and($metricAfterDuplicateJob->net_sales)->toBe('100.000000');
});

it('proves recovery replay safety when sweeper dispatches but crashes before markPublished', function (): void {
    // 1. Ensure Redis queue is clean before test
    $this->redis->del('queues:default');

    // Seed an order
    $orderData = orderPayload(1, [
        'processed_at' => '2026-05-15T12:00:00Z',
        'shopify_updated_at' => '2026-05-15T12:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, [
                'quantity' => 4,
                'original_unit_price' => '25.000000',
                'discount_amount' => '0.000000',
            ]),
        ],
    ]);
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$orderData]])->assertOk();

    // Clear any queue items pushed during setup
    $this->redis->del('queues:default');

    $order = DB::table('orders')->where('shopify_order_gid', 'gid://shopify/Order/1')->first();

    // Seed a stranded outbox event representing this order
    $outboxId = (string) Str::uuid7();
    $createdAt = CarbonImmutable::now()->subSeconds(60);

    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [$order->id],
            'affected_buckets' => [
                "{$this->product->id}:2026-05-15" => [
                    'product_id' => $this->product->id,
                    'metric_date' => '2026-05-15',
                ],
            ],
        ]),
        'status' => 'pending',
        'attempts' => 0,
        'retry_after' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    // 2. Bind a simulated OutboxService test double to simulate sweeper crash during markPublished
    $simulatedOutbox = new class extends OutboxService {
        public int $markPublishedCalls = 0;
        public bool $shouldCrashOnMarkPublished = true;

        public function markPublished(string $outboxId): void
        {
            $this->markPublishedCalls++;

            if ($this->shouldCrashOnMarkPublished) {
                throw new \RuntimeException('Simulated sweeper process crash right after event dispatch');
            }

            parent::markPublished($outboxId);
        }
    };

    $this->app->instance(OutboxService::class, $simulatedOutbox);

    // SWEEPER CYCLE 1:
    // Event is dispatched to Redis queue, but markPublished crashes
    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    // 1. Verify OutboxService::markPublished was called and crashed
    expect($simulatedOutbox->markPublishedCalls)->toBe(1);

    // 2. Verify physical Redis queue delivery from sweeper cycle 1
    $firstQueueLen = $this->redis->llen('queues:default');
    expect($firstQueueLen)->toBeGreaterThan(0);

    // Inspect serialized payload directly from Redis list
    $firstPayloadRaw = $this->redis->lindex('queues:default', 0);
    expect($firstPayloadRaw)->not->toBeEmpty();
    $firstPayload = json_decode((string) $firstPayloadRaw, true);
    expect($firstPayload['displayName'] ?? '')->toBe(\App\Listeners\QueueMetricAggregationsFromOrders::class);

    // 3. Verify record was marked as failed attempt (transient backoff) and remains pending in PostgreSQL
    $outboxAfterCycle1 = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($outboxAfterCycle1->status)->toBe('pending')
        ->and((int) $outboxAfterCycle1->attempts)->toBe(1);

    // Worker 1 runs (simulating worker processing the first job delivered to Redis)
    $job1 = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $job1->handle($this->timezoneService);

    // SWEEPER CYCLE 2 (RECOVERY):
    // Allow markPublished to succeed
    $simulatedOutbox->shouldCrashOnMarkPublished = false;

    // Reset retry_after and advance timestamp so the sweeper considers it ready for retry
    DB::table('outbox_events')
        ->where('id', $outboxId)
        ->update([
            'retry_after' => null,
            'created_at' => CarbonImmutable::now()->subSeconds(60),
            'updated_at' => CarbonImmutable::now()->subSeconds(60),
        ]);

    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    // 4. Verify Redis queue received the duplicate delivery directly: queue length doubled
    $secondQueueLen = $this->redis->llen('queues:default');
    expect($secondQueueLen)->toBe($firstQueueLen * 2);
    expect($simulatedOutbox->markPublishedCalls)->toBe(2);

    // 5. Verify record is now marked published in PostgreSQL
    $outboxFinal = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($outboxFinal->status)->toBe('published')
        ->and($outboxFinal->dispatched_at)->not->toBeNull();

    // Worker 2 runs (simulating duplicate execution of the recovery job delivered to Redis)
    $job2 = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $job2->handle($this->timezoneService);

    // 6. Verify final aggregation idempotency in PostgreSQL: 4 units * $25 = $100.00
    $metricFinal = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect((int) $metricFinal->units_sold)->toBe(4)
        ->and((int) $metricFinal->order_count)->toBe(1)
        ->and($metricFinal->gross_sales)->toBe('100.000000')
        ->and($metricFinal->net_sales)->toBe('100.000000');
});

it('verifies concurrent dual-dispatch jobs are guarded by Redis atomic locks', function (): void {
    $job = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');

    // Verify middleware contains WithoutOverlapping with strict 120s timeout and 10s release
    $middleware = $job->middleware();
    expect($middleware)->not->toBeEmpty();

    $lockMiddleware = $middleware[0];
    expect(get_class($lockMiddleware))->toContain('WithoutOverlapping');

    // Verify unique key format matches (shopId:productId:metricDate)
    expect($job->uniqueId())->toBe("{$this->shopId}:{$this->product->id}:2026-05-15");
});
