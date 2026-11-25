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
    $this->shopDomain = 'retry-backoff.myshopify.com';
    $this->shopId = installShop($this->shopDomain, 'UTC');

    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1)],
    ], $this->shopDomain)->assertOk();

    $this->productId = DB::table('products')->where('shop_id', $this->shopId)->value('id');
});

it('increments attempts and applies exponential backoff on transient broker failure without immediately failing', function (): void {
    $outboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [
                ['product_id' => $this->productId, 'metric_date' => '2026-08-01'],
            ],
        ]),
        'status' => 'pending',
        'attempts' => 0,
        'retry_after' => null,
        'last_error' => null,
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(2),
        'updated_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    // Simulate transient broker failure during recovery sweeper dispatch
    Event::listen(OrdersIngested::class, function (): void {
        throw new RuntimeException('Connection timed out [tcp://127.0.0.1:6379]');
    });

    // Run recovery sweeper
    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // Verify record: MUST remain 'pending', attempts = 1, retry_after set in future, last_error recorded
    $record = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($record->status)->toBe('pending')
        ->and($record->attempts)->toBe(1)
        ->and($record->last_error)->toContain('Connection timed out')
        ->and($record->retry_after)->not->toBeNull()
        ->and(CarbonImmutable::parse($record->retry_after)->isFuture())->toBeTrue();
});

it('ignores pending records whose retry_after timestamp is still in the future', function (): void {
    $outboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [
                ['product_id' => $this->productId, 'metric_date' => '2026-08-01'],
            ],
        ]),
        'status' => 'pending',
        'attempts' => 1,
        'retry_after' => CarbonImmutable::now()->addMinutes(5), // In the future
        'last_error' => 'Previous transient error',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    Queue::fake([AggregateProductDailyMetricsJob::class]);

    // Run recovery sweeper
    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // Verify record was untouched: attempts still 1, status still pending
    $record = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($record->status)->toBe('pending')
        ->and($record->attempts)->toBe(1)
        ->and($record->dispatched_at)->toBeNull();

    Queue::assertNothingPushed();
});

it('successfully recovers and publishes an event once its retry_after threshold elapses and broker recovers', function (): void {
    $outboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [
                ['product_id' => $this->productId, 'metric_date' => '2026-08-01'],
            ],
        ]),
        'status' => 'pending',
        'attempts' => 2,
        'retry_after' => CarbonImmutable::now()->subSecond(), // Elapsed!
        'last_error' => 'Previous transient timeout',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    Queue::fake([AggregateProductDailyMetricsJob::class]);

    // Run recovery sweeper
    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // Verify record transitioned to published
    $record = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($record->status)->toBe('published')
        ->and($record->dispatched_at)->not->toBeNull();

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, fn ($job) => $job->productId === $this->productId && $job->metricDate === '2026-08-01'
    );
});

it('transitions to failed only after exceeding maximum retry attempts (5 attempts)', function (): void {
    $outboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode([
            'shop_id' => $this->shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [
                ['product_id' => $this->productId, 'metric_date' => '2026-08-01'],
            ],
        ]),
        'status' => 'pending',
        'attempts' => 4, // 5th attempt will be reached
        'retry_after' => CarbonImmutable::now()->subSecond(),
        'last_error' => 'Repeated connection refused',
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(10),
        'updated_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    // Broker fails again on the 5th attempt
    Event::listen(OrdersIngested::class, function (): void {
        throw new RuntimeException('Redis broker permanently unreachable');
    });

    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // Now it MUST transition to 'failed' because attempts reached max (5)
    $record = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($record->status)->toBe('failed')
        ->and($record->attempts)->toBe(5)
        ->and($record->last_error)->toContain('Redis broker permanently unreachable');
});

it('immediately marks permanent poison pills as failed without consuming retry attempts', function (): void {
    $poisonPillId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $poisonPillId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 999, // Unsupported version throws InvalidArgumentException
        'payload' => json_encode(['corrupt' => 'schema']),
        'status' => 'pending',
        'attempts' => 0,
        'retry_after' => null,
        'last_error' => null,
        'dispatched_at' => null,
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    $this->artisan('outbox:publish', ['--age' => 30, '--limit' => 10])
        ->assertSuccessful();

    // Permanent failure: marked failed immediately
    $record = DB::table('outbox_events')->where('id', $poisonPillId)->first();
    expect($record->status)->toBe('failed')
        ->and($record->last_error)->toContain('Unsupported outbox event version: 999');
});
