<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Ingestion\Services\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('records an outbox event with pending status and valid payload', function () {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'outbox-test.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $event = new OrdersIngested($shopId, [$orderId], [['product_id' => $productId, 'metric_date' => '2026-05-15']]);

    $service = app(OutboxService::class);
    $outboxId = $service->record($shopId, $event);

    expect(Str::isUuid($outboxId))->toBeTrue();

    $row = DB::table('outbox_events')->where('id', $outboxId)->first();

    expect($row)->not->toBeNull()
        ->and($row->shop_id)->toBe($shopId)
        ->and($row->event_class)->toBe(OrdersIngested::class)
        ->and($row->status)->toBe('pending')
        ->and($row->dispatched_at)->toBeNull();

    $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['shop_id'])->toBe($shopId)
        ->and($payload['order_ids'])->toBe([$orderId])
        ->and($payload['affected_buckets'][0]['product_id'])->toBe($productId)
        ->and($payload['affected_buckets'][0]['metric_date'])->toBe('2026-05-15');
});

it('marks an outbox event as published with timestamp', function () {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'publish-test.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $event = new OrdersIngested($shopId, [(string) Str::uuid()]);
    $service = app(OutboxService::class);
    $outboxId = $service->record($shopId, $event);

    $service->markPublished($outboxId);

    $row = DB::table('outbox_events')->where('id', $outboxId)->first();

    expect($row->status)->toBe('published')
        ->and($row->dispatched_at)->not->toBeNull();
});

it('retrieves only stranded pending events older than cutoff', function () {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'stranded-test.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(OutboxService::class);

    // Event 1: Stranded pending event (created 2 minutes ago)
    $strandedId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $strandedId,
        'shop_id' => $shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode(['shop_id' => $shopId, 'order_ids' => [(string) Str::uuid()], 'affected_buckets' => []]),
        'status' => 'pending',
        'created_at' => CarbonImmutable::now()->subMinutes(2),
        'updated_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    // Event 2: Fresh pending event (created just now, within 30s threshold)
    $freshId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $freshId,
        'shop_id' => $shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode(['shop_id' => $shopId, 'order_ids' => [(string) Str::uuid()], 'affected_buckets' => []]),
        'status' => 'pending',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    // Event 3: Already published event
    $publishedId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $publishedId,
        'shop_id' => $shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode(['shop_id' => $shopId, 'order_ids' => [(string) Str::uuid()], 'affected_buckets' => []]),
        'status' => 'published',
        'dispatched_at' => CarbonImmutable::now(),
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    // Cutoff: 30 seconds ago
    $cutoff = CarbonImmutable::now()->subSeconds(30);
    $stranded = $service->getPendingStrandedEvents($cutoff);

    expect($stranded)->toHaveCount(1)
        ->and($stranded[0]->id)->toBe($strandedId);
});

it('reconstitutes OrdersIngested and RefundsIngested domain events from outbox records', function () {
    $shopId = (string) Str::uuid();
    $orderId = (string) Str::uuid();
    $productId = (string) Str::uuid();

    $service = app(OutboxService::class);

    $ordersRecord = (object) [
        'id' => (string) Str::uuid7(),
        'shop_id' => $shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode([
            'shop_id' => $shopId,
            'order_ids' => [$orderId],
            'affected_buckets' => [
                ['product_id' => $productId, 'metric_date' => '2026-05-15'],
            ],
        ]),
    ];

    $refundsRecord = (object) [
        'id' => (string) Str::uuid7(),
        'shop_id' => $shopId,
        'event_class' => RefundsIngested::class,
        'payload' => json_encode([
            'shop_id' => $shopId,
            'order_ids' => [$orderId],
            'affected_buckets' => [
                ['product_id' => $productId, 'metric_date' => '2026-05-15'],
            ],
        ]),
    ];

    $reconstitutedOrders = $service->reconstitute($ordersRecord);
    expect($reconstitutedOrders)->toBeInstanceOf(OrdersIngested::class)
        ->and($reconstitutedOrders->shopId)->toBe($shopId)
        ->and($reconstitutedOrders->orderIds)->toBe([$orderId])
        ->and($reconstitutedOrders->affectedBuckets)->toHaveCount(1)
        ->and($reconstitutedOrders->affectedBuckets[0]['product_id'])->toBe($productId)
        ->and($reconstitutedOrders->affectedBuckets[0]['metric_date'])->toBe('2026-05-15');

    $reconstitutedRefunds = $service->reconstitute($refundsRecord);
    expect($reconstitutedRefunds)->toBeInstanceOf(RefundsIngested::class)
        ->and($reconstitutedRefunds->shopId)->toBe($shopId)
        ->and($reconstitutedRefunds->orderIds)->toBe([$orderId])
        ->and($reconstitutedRefunds->affectedBuckets)->toHaveCount(1)
        ->and($reconstitutedRefunds->affectedBuckets[0]['product_id'])->toBe($productId)
        ->and($reconstitutedRefunds->affectedBuckets[0]['metric_date'])->toBe('2026-05-15');
});
