<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Events\OrdersIngested;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shopId = installShop('outbox-prune.myshopify.com', 'UTC');
});

it('prunes published outbox events older than the retention threshold while preserving pending and recent events', function (): void {
    $oldPublishedId1 = (string) Str::uuid7();
    $oldPublishedId2 = (string) Str::uuid7();
    $recentPublishedId = (string) Str::uuid7();
    $oldPendingId = (string) Str::uuid7();

    // 1. Two published records older than 14 days (15 days old)
    DB::table('outbox_events')->insert([
        [
            'id' => $oldPublishedId1,
            'shop_id' => $this->shopId,
            'event_class' => OrdersIngested::class,
            'payload' => '{}',
            'status' => 'published',
            'dispatched_at' => CarbonImmutable::now()->subDays(15),
            'created_at' => CarbonImmutable::now()->subDays(15),
            'updated_at' => CarbonImmutable::now()->subDays(15),
            'event_version' => 1,
        ],
        [
            'id' => $oldPublishedId2,
            'shop_id' => $this->shopId,
            'event_class' => OrdersIngested::class,
            'payload' => '{}',
            'status' => 'published',
            'dispatched_at' => CarbonImmutable::now()->subDays(20),
            'created_at' => CarbonImmutable::now()->subDays(20),
            'updated_at' => CarbonImmutable::now()->subDays(20),
            'event_version' => 1,
        ],
        // 2. Recent published record (2 days old) - must be preserved
        [
            'id' => $recentPublishedId,
            'shop_id' => $this->shopId,
            'event_class' => OrdersIngested::class,
            'payload' => '{}',
            'status' => 'published',
            'dispatched_at' => CarbonImmutable::now()->subDays(2),
            'created_at' => CarbonImmutable::now()->subDays(2),
            'updated_at' => CarbonImmutable::now()->subDays(2),
            'event_version' => 1,
        ],
        // 3. Old pending record (15 days old) - must NEVER be pruned
        [
            'id' => $oldPendingId,
            'shop_id' => $this->shopId,
            'event_class' => OrdersIngested::class,
            'payload' => '{}',
            'status' => 'pending',
            'dispatched_at' => null,
            'created_at' => CarbonImmutable::now()->subDays(15),
            'updated_at' => CarbonImmutable::now()->subDays(15),
            'event_version' => 1,
        ],
    ]);

    $this->artisan('outbox:prune --days=14')
        ->expectsOutputToContain('Pruned 2 published outbox events.')
        ->assertSuccessful();

    // Verify old published records are deleted
    expect(DB::table('outbox_events')->whereIn('id', [$oldPublishedId1, $oldPublishedId2])->count())->toBe(0)
        // Verify recent published record is preserved
        ->and(DB::table('outbox_events')->where('id', $recentPublishedId)->exists())->toBeTrue()
        // Verify old pending record is preserved
        ->and(DB::table('outbox_events')->where('id', $oldPendingId)->exists())->toBeTrue();
});

it('supports custom retention threshold via --days option', function (): void {
    $fiveDayOldPublishedId = (string) Str::uuid7();

    DB::table('outbox_events')->insert([
        'id' => $fiveDayOldPublishedId,
        'shop_id' => $this->shopId,
        'event_class' => OrdersIngested::class,
        'payload' => '{}',
        'status' => 'published',
        'dispatched_at' => CarbonImmutable::now()->subDays(5),
        'created_at' => CarbonImmutable::now()->subDays(5),
        'updated_at' => CarbonImmutable::now()->subDays(5),
        'event_version' => 1,
    ]);

    $this->artisan('outbox:prune --days=3')
        ->expectsOutputToContain('Pruned 1 published outbox events.')
        ->assertSuccessful();

    expect(DB::table('outbox_events')->where('id', $fiveDayOldPublishedId)->count())->toBe(0);
});

it('outputs info message when no records meet the pruning criteria', function (): void {
    $this->artisan('outbox:prune --days=14')
        ->expectsOutputToContain('No expired published outbox events found to prune.')
        ->assertSuccessful();
});
