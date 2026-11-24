<?php

declare(strict_types=1);

use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->shopId = installShop();
});

if (! function_exists('s2sGet')) {
    function s2sGet(string $path, string $shopDomain = 'acme.myshopify.com'): TestResponse
    {
        config()->set('services.s2s.secret', INGESTION_TEST_SECRET);

        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $canonical = implode("\n", ['GET', $path, $timestamp, $nonce, $shopDomain, hash('sha256', '')]);

        return test()->call('GET', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_APP_SHOP_DOMAIN' => $shopDomain,
            'HTTP_X_APP_TIMESTAMP' => $timestamp,
            'HTTP_X_APP_NONCE' => $nonce,
            'HTTP_X_APP_SIGNATURE' => hash_hmac('sha256', $canonical, INGESTION_TEST_SECRET),
        ]);
    }
}

describe('GET /internal/v1/sync/status', function (): void {
    it('returns null sync_run when shop has never synced', function (): void {
        s2sGet('/internal/v1/sync/status')
            ->assertOk()
            ->assertExactJson(['sync_run' => null]);
    });

    it('returns the latest sync_run for the shop', function (): void {
        $run = SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
            'records_received' => 120,
            'records_processed' => 120,
            'started_at' => '2026-11-18 10:00:00+00',
            'completed_at' => '2026-11-18 10:05:00+00',
        ]);

        s2sGet('/internal/v1/sync/status')
            ->assertOk()
            ->assertJson([
                'sync_run' => [
                    'id' => $run->id,
                    'sync_type' => 'initial',
                    'status' => 'completed',
                    'records_received' => 120,
                    'records_processed' => 120,
                    'started_at' => $run->started_at->toISOString(),
                    'completed_at' => $run->completed_at->toISOString(),
                    'error_code' => null,
                ],
            ]);
    });

    it('returns the most recent sync run tie-breaking same second by id', function (): void {
        $now = now();

        $firstRun = SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
            'records_received' => 50,
            'records_processed' => 50,
            'started_at' => $now,
            'created_at' => $now,
        ]);

        $secondRun = SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Running,
            'records_received' => 10,
            'records_processed' => 5,
            'started_at' => $now,
            'created_at' => $now,
        ]);

        $expectedWinner = strcmp($firstRun->id, $secondRun->id) > 0 ? $firstRun : $secondRun;

        $response = s2sGet('/internal/v1/sync/status')
            ->assertOk();

        expect($response->json('sync_run.id'))->toBe($expectedWinner->id);
    });

    it('ignores sync runs created prior to the shop reinstall', function (): void {
        // Create run for current install and backdate its created_at
        $run = SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
            'records_received' => 100,
            'records_processed' => 100,
            'started_at' => now()->subHours(2),
        ]);
        \Illuminate\Support\Facades\DB::table('sync_runs')->where('id', $run->id)->update([
            'created_at' => now()->subHours(2),
        ]);

        // Uninstall shop
        s2sPost('/internal/v1/ingestion/shop-uninstall', [
            'uninstalled_at' => now()->subHour()->toISOString(),
        ])->assertOk();

        // Reinstall shop (installed_at is updated to now)
        installShop('acme.myshopify.com');

        // Status must be null because the previous run happened before the new install
        s2sGet('/internal/v1/sync/status')
            ->assertOk()
            ->assertExactJson(['sync_run' => null]);
    });

    it('returns 403 Forbidden for an uninstalled shop', function (): void {
        s2sPost('/internal/v1/ingestion/shop-uninstall', [
            'uninstalled_at' => now()->toISOString(),
        ])->assertOk();

        s2sGet('/internal/v1/sync/status')
            ->assertForbidden();
    });

    it('enforces tenant isolation across shops', function (): void {
        SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
            'records_received' => 100,
            'records_processed' => 100,
            'started_at' => '2026-11-18 10:00:00+00',
            'completed_at' => '2026-11-18 10:05:00+00',
        ]);

        installShop('other-store.myshopify.com');

        s2sGet('/internal/v1/sync/status', 'other-store.myshopify.com')
            ->assertOk()
            ->assertExactJson(['sync_run' => null]);
    });

    it('requires a valid S2S signature', function (): void {
        test()->getJson('/internal/v1/sync/status', [
            'X-App-Shop-Domain' => 'acme.myshopify.com',
        ])->assertUnauthorized();
    });

    it('returns 404 for a shop that was never installed', function (): void {
        s2sGet('/internal/v1/sync/status', 'uninstalled-shop.myshopify.com')
            ->assertNotFound();
    });

    it('returns the history notice of the latest run', function (): void {
        $run = SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
        ]);
        DB::table('sync_runs')->where('id', $run->id)->update(['history_notice' => 'Order history limited to the last 60 days.']);

        s2sGet('/internal/v1/sync/status')
            ->assertOk()
            ->assertJsonPath('sync_run.history_notice', 'Order history limited to the last 60 days.');
    });

    it('returns a null history notice key when the run has none', function (): void {
        SyncRun::query()->create([
            'shop_id' => $this->shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
        ]);

        $syncRun = s2sGet('/internal/v1/sync/status')->assertOk()->json('sync_run');

        expect($syncRun)->toHaveKey('history_notice')
            ->and($syncRun['history_notice'])->toBeNull();
    });
});
