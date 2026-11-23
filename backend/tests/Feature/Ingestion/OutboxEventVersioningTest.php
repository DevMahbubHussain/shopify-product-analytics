<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Ingestion\Services\OutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('versioning-test.myshopify.com', 'UTC');
    $this->service = app(OutboxService::class);
});

it('records an outbox event with default version 1', function (): void {
    $orderId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $event = new OrdersIngested($this->shopId, [$orderId], [['product_id' => $productId, 'metric_date' => '2026-05-15']]);

    $outboxId = $this->service->record($this->shopId, $event);

    $row = DB::table('outbox_events')->where('id', $outboxId)->first();

    expect($row)->not->toBeNull()
        ->and($row->event_version)->toBe(1);
});

it('records an outbox event with explicit custom version', function (): void {
    $orderId = (string) Str::uuid();
    $event = new RefundsIngested($this->shopId, [$orderId], []);

    $outboxId = $this->service->record($this->shopId, $event, eventVersion: 2);

    $row = DB::table('outbox_events')->where('id', $outboxId)->first();

    expect($row)->not->toBeNull()
        ->and($row->event_version)->toBe(2);
});

it('reconstitutes event from outbox record preserving event state', function (): void {
    $orderId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $event = new OrdersIngested($this->shopId, [$orderId], [['product_id' => $productId, 'metric_date' => '2026-05-15']]);

    $outboxId = $this->service->record($this->shopId, $event, eventVersion: 1);
    $row = DB::table('outbox_events')->where('id', $outboxId)->first();

    $reconstituted = $this->service->reconstitute($row);

    expect($reconstituted)->toBeInstanceOf(OrdersIngested::class)
        ->and($reconstituted->shopId)->toBe($this->shopId)
        ->and($reconstituted->orderIds)->toBe([$orderId])
        ->and($reconstituted->affectedBuckets)->toHaveCount(1);
});

it('throws an InvalidArgumentException when attempting to reconstitute an unsupported event version', function (): void {
    $outboxId = (string) Str::uuid7();
    DB::table('outbox_events')->insert([
        'id' => $outboxId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'event_version' => 99,
        'payload' => json_encode(['shop_id' => $this->shopId], JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('outbox_events')->where('id', $outboxId)->first();

    $this->service->reconstitute($row);
})->throws(InvalidArgumentException::class, 'Unsupported outbox event version: 99');
