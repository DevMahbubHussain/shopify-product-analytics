<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use App\Ingestion\Services\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopId = installShop();
    $this->outboxService = app(OutboxService::class);
});

it('retries pending outbox events with exponential backoff upon transient Redis broker failures', function (): void {
    // Seed an outbox event that was created 60 seconds ago (ready for sweeper)
    $outboxId = (string) Str::uuid7();
    $createdAt = CarbonImmutable::now()->subSeconds(60);

    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode(['shop_id' => $this->shopId, 'order_ids' => [], 'affected_buckets' => []]),
        'status' => 'pending',
        'attempts' => 0,
        'retry_after' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    // Simulate transient Redis connection failure on event dispatch
    Event::listen(OrdersIngested::class, function (): never {
        throw new \RuntimeException('Redis connection timed out after 3000ms');
    });

    // Run sweeper command
    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    // 1. Assert attempts incremented, status remains pending, retry_after is set, and error is stored
    $eventRecord = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect($eventRecord->status)->toBe('pending')
        ->and((int) $eventRecord->attempts)->toBe(1)
        ->and($eventRecord->last_error)->toContain('Redis connection timed out')
        ->and($eventRecord->retry_after)->not->toBeNull();

    // Verify exponential backoff formula: 2^1 * 5 = 10 seconds from execution time
    $retryAfter = CarbonImmutable::parse($eventRecord->retry_after);
    expect($retryAfter->diffInSeconds(CarbonImmutable::now()))->toBeLessThanOrEqual(12);

    // 2. Sweeper run while still within backoff window must skip the event
    $skippedEvents = $this->outboxService->getPendingStrandedEvents(CarbonImmutable::now());
    expect($skippedEvents->pluck('id'))->not->toContain($outboxId);

    // 3. Fast-forward clock past retry_after: event becomes eligible again
    CarbonImmutable::setTestNow($retryAfter->addSecond());

    $eligibleEvents = $this->outboxService->getPendingStrandedEvents(CarbonImmutable::now());
    expect($eligibleEvents->pluck('id'))->toContain($outboxId);

    // Run sweeper again while Redis is still down
    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    $eventRecordSecondRetry = DB::table('outbox_events')->where('id', $outboxId)->first();
    expect((int) $eventRecordSecondRetry->attempts)->toBe(2)
        ->and($eventRecordSecondRetry->status)->toBe('pending');

    // Backoff for attempt 2: 2^2 * 5 = 20 seconds
    $secondRetryAfter = CarbonImmutable::parse($eventRecordSecondRetry->retry_after);
    expect($secondRetryAfter->diffInSeconds(CarbonImmutable::now()))->toBeLessThanOrEqual(22);

    CarbonImmutable::setTestNow();
});

it('transitions event status to failed and halts retries after exceeding retry threshold (5 attempts)', function (): void {
    $outboxId = (string) Str::uuid7();
    $createdAt = CarbonImmutable::now()->subMinutes(10);

    // Seed event that has already failed 4 times
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode(['shop_id' => $this->shopId, 'order_ids' => [], 'affected_buckets' => []]),
        'status' => 'pending',
        'attempts' => 4,
        'retry_after' => CarbonImmutable::now()->subSeconds(5), // backoff already elapsed
        'created_at' => $createdAt,
        'updated_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    // Fifth attempt fails due to persistent broker outage
    Event::listen(OrdersIngested::class, function (): never {
        throw new \RuntimeException('Persistent Redis cluster unreachable');
    });

    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    $eventRecord = DB::table('outbox_events')->where('id', $outboxId)->first();

    // Must be transitioned to 'failed', attempts = 5, retry_after cleared
    expect($eventRecord->status)->toBe('failed')
        ->and((int) $eventRecord->attempts)->toBe(5)
        ->and($eventRecord->retry_after)->toBeNull()
        ->and($eventRecord->last_error)->toContain('Persistent Redis cluster unreachable');

    // Future sweeper calls must never pick up exhausted failed records
    $stranded = $this->outboxService->getPendingStrandedEvents(CarbonImmutable::now());
    expect($stranded->pluck('id'))->not->toContain($outboxId);
});

it('isolates permanent poison pills immediately as failed without consuming retry attempts', function (): void {
    $outboxId = (string) Str::uuid7();
    $createdAt = CarbonImmutable::now()->subMinutes(5);

    // Seed an event with an unknown/unsupported event class (poison pill)
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => 'App\\Events\\UnknownCorruptEventClass',
        'event_version' => 999,
        'payload' => json_encode(['malformed' => true]),
        'status' => 'pending',
        'attempts' => 0,
        'retry_after' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    $eventRecord = DB::table('outbox_events')->where('id', $outboxId)->first();

    // Immediately marked failed without waiting for 5 retry cycles
    expect($eventRecord->status)->toBe('failed')
        ->and((int) $eventRecord->attempts)->toBe(0)
        ->and($eventRecord->retry_after)->toBeNull()
        ->and($eventRecord->last_error)->toContain('Unsupported outbox event');
});

it('successfully publishes pending events once Redis broker recovers', function (): void {
    $outboxId = (string) Str::uuid7();
    $createdAt = CarbonImmutable::now()->subMinutes(2);

    // Event that had 2 prior failed attempts but whose backoff window has now passed
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 1,
        'payload' => json_encode(['shop_id' => $this->shopId, 'order_ids' => ['order_recovered_1'], 'affected_buckets' => []]),
        'status' => 'pending',
        'attempts' => 2,
        'retry_after' => CarbonImmutable::now()->subSeconds(10),
        'created_at' => $createdAt,
        'updated_at' => CarbonImmutable::now()->subMinutes(1),
    ]);

    $dispatched = false;
    Event::listen(OrdersIngested::class, function () use (&$dispatched): void {
        $dispatched = true;
    });

    Artisan::call('outbox:publish', ['--age' => 30, '--limit' => 10]);

    $eventRecord = DB::table('outbox_events')->where('id', $outboxId)->first();

    expect($dispatched)->toBeTrue()
        ->and($eventRecord->status)->toBe('published')
        ->and($eventRecord->dispatched_at)->not->toBeNull();
});
