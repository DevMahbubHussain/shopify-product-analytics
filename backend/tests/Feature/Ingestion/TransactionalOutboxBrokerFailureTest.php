<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopDomain = 'broker-failure.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'UTC');

    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], $this->shopDomain)->assertOk();

    $this->productId = DB::table('products')->where('shop_id', $this->shopId)->value('id');
});

it('gracefully completes HTTP ingestion when fast-path Redis queue dispatch fails, preserving pending outbox record', function (): void {
    // 1. Simulate Redis broker connection failure during fast-path event dispatch
    $throwBrokerError = true;
    Event::listen(OrdersIngested::class, function () use (&$throwBrokerError): void {
        if ($throwBrokerError) {
            throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]: Redis broker temporarily unreachable');
        }
    });

    $order = orderPayload(1, [
        'processed_at' => '2026-07-01T12:00:00Z',
        'shopify_updated_at' => '2026-07-01T12:00:00Z',
        'line_items' => [
            lineItemPayload(1, 1, 1, ['quantity' => 2, 'original_unit_price' => '25.00']),
        ],
    ]);

    // Fast-path should catch the broker exception and return 200 OK so Shopify does not retry the webhook
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]], $this->shopDomain)
        ->assertOk()
        ->assertJson(['received' => 1, 'written' => 1]);

    // 2. Verify database: order data is safely persisted in PostgreSQL
    expect(DB::table('orders')->where('shop_id', $this->shopId)->count())->toBe(1)
        ->and(DB::table('order_line_items')->where('shop_id', $this->shopId)->count())->toBe(1);

    // 3. Verify outbox record: safely preserved with status = 'pending' and dispatched_at = null
    $outbox = DB::table('outbox_events')
        ->where('shop_id', $this->shopId)
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->status)->toBe('pending')
        ->and($outbox->dispatched_at)->toBeNull();

    // 4. Simulate Redis broker recovery: run the background sweeper
    // Age the stranded record past the cutoff
    DB::table('outbox_events')
        ->where('id', $outbox->id)
        ->update(['created_at' => CarbonImmutable::now()->subMinutes(1)]);

    // Allow broker dispatch to succeed
    $throwBrokerError = false;
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    // Run recovery sweeper
    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // Verify record transitioned to published
    $recoveredOutbox = DB::table('outbox_events')->where('id', $outbox->id)->first();
    expect($recoveredOutbox->status)->toBe('published')
        ->and($recoveredOutbox->dispatched_at)->not->toBeNull();

    // Verify downstream metric aggregation job was queued
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) =>
        $job->productId === $this->productId && $job->metricDate === '2026-07-01'
    );
});

it('isolates poison pill corrupted outbox records as failed without crashing subsequent valid recovery', function (): void {
    Queue::fake([AggregateProductDailyMetricsJob::class]);

    // 1. Insert a poison pill record (corrupted unsupported event version)
    $poisonPillId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $poisonPillId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 999, // Unsupported future schema version
        'payload' => json_encode(['corrupt' => 'payload']),
        'status' => 'pending',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinute(),
        'updated_at' => CarbonImmutable::now()->subMinute(),
    ]);

    // 2. Insert a valid stranded record right behind it
    $validOutboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $validOutboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [
                ['product_id' => $this->productId, 'metric_date' => '2026-07-05'],
            ],
        ]),
        'status' => 'pending',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subSeconds(45),
        'updated_at' => CarbonImmutable::now()->subSeconds(45),
    ]);

    // 3. Execute recovery sweeper
    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // 4. Assert poison pill record was marked 'failed' so it will never be queried again
    $poisonPill = DB::table('outbox_events')->where('id', $poisonPillId)->first();
    expect($poisonPill->status)->toBe('failed');

    // 5. Assert valid record was successfully published
    $validRecord = DB::table('outbox_events')->where('id', $validOutboxId)->first();
    expect($validRecord->status)->toBe('published')
        ->and($validRecord->dispatched_at)->not->toBeNull();

    // 6. Assert queue job for valid record was dispatched
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) =>
        $job->productId === $this->productId && $job->metricDate === '2026-07-05'
    );
});
