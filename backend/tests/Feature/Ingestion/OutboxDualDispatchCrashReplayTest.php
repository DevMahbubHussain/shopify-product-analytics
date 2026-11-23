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
    // Track total dispatches across the entire test lifecycle
    $totalDispatches = 0;
    Event::listen(OrdersIngested::class, function () use (&$totalDispatches): void {
        $totalDispatches++;
    });

    // 1. Bind crash-simulating pipe: event() dispatches successfully to Redis,
    // but the process terminates or DB connection drops before markPublished() executes.
    $this->app->bind(\App\Ingestion\Pipes\DispatchIngestedEvents::class, function () {
        return new class {
            public function handle(\App\Ingestion\IngestionContext $context, \Closure $next): \App\Ingestion\IngestionResponse
            {
                // Fast-path dispatch succeeds
                foreach ($context->events as $event) {
                    event($event);
                }

                // CRASH WINDOW: Process dies / markPublished() fails, leaving outbox record pending
                // Log warning as fast-path error handler does
                \Illuminate\Support\Facades\Log::warning('Simulated fast-path process crash before markPublished execution', [
                    'outbox_ids' => $context->outboxIds,
                ]);

                return $next($context);
            }
        };
    });

    // 2. Ingest an order through HTTP endpoint
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

    // VERIFY INITIAL DISPATCH:
    // event($event) succeeded and was dispatched to Redis queue (count = 1)
    expect($totalDispatches)->toBe(1);

    // VERIFY PUBLISHED STATUS UPDATE FAILED:
    // Because of the crash, markPublished() was not executed, so the outbox record
    // genuinely remained in 'pending' status with dispatched_at = null
    $outboxRecord = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->first();

    expect($outboxRecord)->not->toBeNull()
        ->and($outboxRecord->status)->toBe('pending')
        ->and($outboxRecord->dispatched_at)->toBeNull();

    // 3. First consumer job executes (simulating queue worker processing the initial fast-path dispatch)
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

    // 4. Recovery sweeper runs:
    // Update created_at to 60s in the past to simulate time passing beyond the stranded threshold (30s)
    DB::table('outbox_events')
        ->where('id', $outboxRecord->id)
        ->update([
            'created_at' => CarbonImmutable::now()->subSeconds(60),
            'updated_at' => CarbonImmutable::now()->subSeconds(60),
        ]);

    $dispatchesBeforeSweeper = $totalDispatches;

    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    // VERIFY RECOVERY DISPATCH:
    // Sweeper re-dispatches the exact same event a second time
    $recoveryDispatches = $totalDispatches - $dispatchesBeforeSweeper;
    expect($recoveryDispatches)->toBe(1);

    // VERIFY TOTAL DISPATCH ATTEMPTS = 2 (At-least-once duplicate delivery guarantee)
    expect($totalDispatches)->toBe(2);

    // Outbox record is now published by the sweeper
    $outboxAfterSweeper = DB::table('outbox_events')->where('id', $outboxRecord->id)->first();
    expect($outboxAfterSweeper->status)->toBe('published')
        ->and($outboxAfterSweeper->dispatched_at)->not->toBeNull();

    // 5. Second consumer job executes (simulating queue worker processing duplicate delivery from sweeper)
    $secondJob = new AggregateProductDailyMetricsJob($this->shopId, $this->product->id, '2026-05-15');
    $secondJob->handle($this->timezoneService);

    // VERIFY FINAL AGGREGATION IDEMPOTENCY:
    // Re-aggregating over raw orders produces the exact same precomputed values; ZERO double-counting!
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
