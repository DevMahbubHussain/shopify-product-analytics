<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use App\Ingestion\Services\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('retrieves disjoint sets of pending events across concurrent worker transactions when lock is enabled', function () {
    // Configure a dedicated secondary PDO connection to simulate a parallel background worker
    config(['database.connections.worker2' => config('database.connections.pgsql')]);

    $shopId = installShop('worker-concurrency.myshopify.com', 'UTC');
    $outboxService = app(OutboxService::class);

    // Seed 4 stranded outbox events older than 30 seconds
    $cutoff = CarbonImmutable::now()->subSeconds(30);
    $eventIds = [];

    for ($i = 0; $i < 4; $i++) {
        $id = (string) Str::uuid7();
        $eventIds[] = $id;

        DB::table('outbox_events')->insert([
            'id' => $id,
            'shop_id' => $shopId,
            'event_class' => OrdersIngested::class,
            'payload' => json_encode(['shop_id' => $shopId, 'order_ids' => ["order-{$i}"]], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'dispatched_at' => null,
            'created_at' => CarbonImmutable::now()->subMinutes(5)->addSeconds($i),
            'updated_at' => CarbonImmutable::now()->subMinutes(5)->addSeconds($i),
        ]);
    }

    // Commit seed transaction so independent secondary PDO connection worker2 can see the rows
    DB::commit();

    try {
        $conn1 = DB::connection('pgsql');
        $conn2 = DB::connection('worker2');

        // Worker 1 starts transaction and claims the first 2 stranded events with FOR UPDATE SKIP LOCKED
        $conn1->beginTransaction();
        $worker1Events = $outboxService->getPendingStrandedEvents($cutoff, limit: 2, lock: true);

        // Worker 2 starts transaction concurrently on a separate PDO connection and claims 2 events
        $conn2->beginTransaction();
        $worker2Events = $conn2->table('outbox_events')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at', 'asc')
            ->limit(2)
            ->lock('FOR UPDATE SKIP LOCKED')
            ->get();

        $worker1Ids = $worker1Events->pluck('id')->all();
        $worker2Ids = $worker2Events->pluck('id')->all();

        // Verify both workers acquired 2 items each and their result sets are completely disjoint
        expect($worker1Ids)->toHaveCount(2)
            ->and($worker2Ids)->toHaveCount(2)
            ->and(array_intersect($worker1Ids, $worker2Ids))->toBeEmpty();

        // Worker 1 marks its events published and commits
        $conn1->table('outbox_events')->whereIn('id', $worker1Ids)->update(['status' => 'published']);
        $conn1->commit();

        // Worker 2 marks its events published and commits
        $conn2->table('outbox_events')->whereIn('id', $worker2Ids)->update(['status' => 'published']);
        $conn2->commit();

        // All 4 events must now be published
        $publishedCount = DB::table('outbox_events')->where('status', 'published')->count();
        expect($publishedCount)->toBe(4);
    } finally {
        DB::table('outbox_events')->where('shop_id', $shopId)->delete();
        DB::table('shops')->where('id', $shopId)->delete();
        DB::beginTransaction();
    }
});

it('skips locked events without blocking and returns an empty collection when all events are claimed', function () {
    config(['database.connections.worker2' => config('database.connections.pgsql')]);

    $shopId = installShop('worker-exhaustion.myshopify.com', 'UTC');
    $cutoff = CarbonImmutable::now()->subSeconds(30);

    for ($i = 0; $i < 2; $i++) {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'event_class' => OrdersIngested::class,
            'payload' => json_encode(['shop_id' => $shopId, 'order_ids' => ["order-{$i}"]], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'dispatched_at' => null,
            'created_at' => CarbonImmutable::now()->subMinutes(5)->addSeconds($i),
            'updated_at' => CarbonImmutable::now()->subMinutes(5)->addSeconds($i),
        ]);
    }

    // Commit seed transaction so independent secondary PDO connection worker2 can see the rows
    DB::commit();

    try {
        $outboxService = app(OutboxService::class);
        $conn1 = DB::connection('pgsql');
        $conn2 = DB::connection('worker2');

        // Worker 1 claims all pending records
        $conn1->beginTransaction();
        $worker1Events = $outboxService->getPendingStrandedEvents($cutoff, limit: 10, lock: true);
        expect($worker1Events)->toHaveCount(2);

        // Worker 2 queries concurrently with lock enabled; all records are locked so it skips them immediately
        $conn2->beginTransaction();
        $worker2Events = $conn2->table('outbox_events')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->lock('FOR UPDATE SKIP LOCKED')
            ->get();

        expect($worker2Events)->toBeEmpty();

        $conn1->rollBack();
        $conn2->rollBack();
    } finally {
        DB::table('outbox_events')->where('shop_id', $shopId)->delete();
        DB::table('shops')->where('id', $shopId)->delete();
        DB::beginTransaction();
    }
});
