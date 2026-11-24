<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Enums\PlanTier;
use App\Enums\SyncRunStatus;
use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use App\Models\SyncRun;
use App\Services\PlanEntitlementService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('sync_status')]
#[Title('Sync Status')]
#[Uri('analytics://shop/sync-status')]
#[MimeType('application/json')]
#[Description('Current background data ingestion status, synchronization freshness, records processed, and data completeness flag for the authenticated store.')]
final class SyncStatusResource extends Resource
{
    use FormatsToolMetadata;

    public function handle(Request $request): Response
    {
        $shop = $this->resolveCurrentShop(request());

        /**
         * @var SyncRun|null $latestRun
         */
        $latestRun = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->orderByDesc('created_at')
            ->first();

        /**
         * @var SyncRun|null $latestCompletedRun
         */
        $latestCompletedRun = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        $status = $latestRun !== null ? strtoupper($latestRun->status->value) : 'NO_SYNC';
        $dataComplete = $latestCompletedRun !== null;
        $lastSyncedAt = $latestCompletedRun?->completed_at?->toIso8601String();
        $historyNotice = $latestRun?->history_notice;

        /**
         * @var PlanTier|null $planTier
         */
        $planTier = request()->attributes->get('mcp_plan_tier') ?? app(PlanEntitlementService::class)->resolvePlan($shop);

        $payload = [
            'status' => $status,
            'sync_type' => $latestRun?->sync_type?->value,
            'records_received' => (int) ($latestRun?->records_received ?? 0),
            'records_processed' => (int) ($latestRun?->records_processed ?? 0),
            'data_complete' => $dataComplete,
            'last_synced_at' => $lastSyncedAt,
            'history_notice' => $historyNotice,
            'currency_code' => $shop->currency_code,
            'timezone' => $shop->timezone ?? 'UTC',
            'plan' => $planTier->value,
        ];

        return Response::json($payload);
    }
}
