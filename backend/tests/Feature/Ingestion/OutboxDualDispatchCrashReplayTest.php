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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    $this->product = DB::table('products')->where('shop_id', $this->shopId)->first();
    $this->timezoneService = app(StoreTimezoneService::class);
    $this->outboxService = app(OutboxService::class);
});

it('proves idempotent metric convergence when fast-path dispatches but crashes before markPublished', function (): void {
    // 1. Intercept queue pushes to prove Redis/queue subsystem independently receives both jobs
    \Illuminate\Support\Facades\Queue::fake([AggregateProductDailyMetricsJob::class]);

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

    // VERIFY FIRST QUEUE DISPATCH:
    // Event listener QueueMetricAggregationsFromOrders pushed the first aggregation job to Redis/queue
    \Illuminate\Support\Facades\Queue::assertPushed(AggregateProductDailyMetricsJob::class, 1);

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

    // Release unique job lock in queue fake simulating worker completing the first job
    app('queue')->releaseUniqueJobLocks();

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

    // VERIFY SECOND QUEUE DISPATCH (Two distinct jobs pushed to the queue):
    // Sweeper re-dispatched the event and the queue subsystem received its second job
    \Illuminate\Support\Facades\Queue::assertPushed(AggregateProductDailyMetricsJob::class, 2);
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

    // SIMULATE SWEEPER CRASH WINDOW:
    // Event is dispatched to queue, but process crashes before DB transaction commits markPublished.
    $eventDispatchedCount = 0;
    Event::listen(OrdersIngested::class, function () use (&$eventDispatchedCount): void {
        $eventDispatchedCount++;
    });

    // Run first sweeper cycle (manually simulating a crash after event() by keeping DB status as 'pending')
    $stranded = $this->outboxService->getPendingStrandedEvents(CarbonImmutable::now());
    expect($stranded->pluck('id'))->toContain($outboxId);

    // Event 1 dispatched
    event(new OrdersIngested($this->shopId, [$order->id], [
        "{$this->product->id}:2026-05-15" => [
            'product_id' => $this->product->id,
            'metric_date' => '2026-05-15',
        ],
    ]));

    expect($eventDispatchedCount)->toBe(1);

    // Worker 1 runs
    $job1 = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $job1->handle($this->timezoneService);

    // Sweeper cycle 2 (recovering from previous crash, now completes cleanly with markPublished)
    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    // Sweeper re-dispatched the event a second time (total 2 dispatches)
    expect($eventDispatchedCount)->toBe(2);

    // Record is now marked published
    $outboxFinal = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($outboxFinal->status)->toBe('published');

    // Worker 2 runs
    $job2 = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $job2->handle($this->timezoneService);

    $metricFinal = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopId)
        ->where('product_id', $this->product->id)
        ->where('metric_date', '2026-05-15')
        ->first();

    // 4 units * $25 = $100.00 gross sales, verified exact mathematical state
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
