<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('publishes stranded pending outbox events and marks them published', function () {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'stranded-command.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid();
    $productId = (string) Str::uuid();

    $strandedOutboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $strandedOutboxId,
        'shop_id' => $shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode([
            'shop_id' => $shopId,
            'order_ids' => [$orderId],
            'affected_buckets' => [
                ['product_id' => $productId, 'metric_date' => '2026-05-15'],
            ],
        ], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'created_at' => CarbonImmutable::now()->subMinutes(2),
        'updated_at' => CarbonImmutable::now()->subMinutes(2),
    ]);

    Event::fake([OrdersIngested::class]);

    $this->artisan('outbox:publish --age=30')
        ->expectsOutputToContain('Published 1 stranded outbox events.')
        ->assertSuccessful();

    Event::assertDispatched(OrdersIngested::class, function (OrdersIngested $event) use ($shopId, $orderId, $productId): bool {
        return $event->shopId === $shopId
            && $event->orderIds === [$orderId]
            && count($event->affectedBuckets) === 1
            && $event->affectedBuckets[0]['product_id'] === $productId;
    });

    $row = DB::table('outbox_events')->where('id', $strandedOutboxId)->first();
    expect($row->status)->toBe('published')
        ->and($row->dispatched_at)->not->toBeNull();
});

it('skips fresh pending events created within the age threshold', function () {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'fresh-command.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $freshOutboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $freshOutboxId,
        'shop_id' => $shopId,
        'event_class' => OrdersIngested::class,
        'payload' => json_encode([
            'shop_id' => $shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [],
        ], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'created_at' => CarbonImmutable::now()->subSeconds(5),
        'updated_at' => CarbonImmutable::now()->subSeconds(5),
    ]);

    Event::fake([OrdersIngested::class]);

    $this->artisan('outbox:publish --age=30')
        ->expectsOutputToContain('No stranded outbox events found.')
        ->assertSuccessful();

    Event::assertNotDispatched(OrdersIngested::class);

    $row = DB::table('outbox_events')->where('id', $freshOutboxId)->first();
    expect($row->status)->toBe('pending')
        ->and($row->dispatched_at)->toBeNull();
});

it('skips already published events', function () {
    $shopId = (string) Str::uuid();
    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'published-command.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $publishedId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $publishedId,
        'shop_id' => $shopId,
        'event_class' => RefundsIngested::class,
        'payload' => json_encode([
            'shop_id' => $shopId,
            'order_ids' => [(string) Str::uuid()],
            'affected_buckets' => [],
        ], JSON_THROW_ON_ERROR),
        'status' => 'published',
        'dispatched_at' => CarbonImmutable::now(),
        'created_at' => CarbonImmutable::now()->subMinutes(5),
        'updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    Event::fake([RefundsIngested::class]);

    $this->artisan('outbox:publish --age=30')
        ->expectsOutputToContain('No stranded outbox events found.')
        ->assertSuccessful();

    Event::assertNotDispatched(RefundsIngested::class);
});
