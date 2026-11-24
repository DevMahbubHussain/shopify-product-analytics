<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PlanTier;
use App\Enums\SyncRunStatus;
use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use App\Models\SyncRun;
use App\Services\PlanEntitlementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetSyncStatusTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'get_sync_status';

    protected string $title = 'Get Sync Status';

    public function description(): string
    {
        return 'Get current data sync status, data completeness flag, store currency, timezone, and freshness timestamp for the authenticated shop. Call this tool when analytics results look empty or incomplete.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Current sync lifecycle status: COMPLETED, RUNNING, FAILED, PENDING, or NO_SYNC'),
            'data_complete' => $schema->boolean()->description('True if at least one sync run has completed successfully for this store'),
            'last_synced_at' => $schema->string()->nullable()->description('ISO 8601 UTC timestamp of last completed sync run'),
            'history_notice' => $schema->string()->nullable()->description('Information about historical sync range or pending data'),
            'currency_code' => $schema->string()->description('ISO 4217 store currency code'),
            'timezone' => $schema->string()->description('Store timezone identifier'),
            'plan' => $schema->string()->description('Merchant subscription plan tier'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([]);

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
        $planTier = request()->attributes->get('mcp_plan_tier');
        if ($planTier === null) {
            $planTier = app(PlanEntitlementService::class)->resolvePlan($shop);
        }

        $payload = [
            'status' => $status,
            'data_complete' => $dataComplete,
            'last_synced_at' => $lastSyncedAt,
            'history_notice' => $historyNotice,
            'currency_code' => $shop->currency_code,
            'timezone' => $shop->timezone ?? 'UTC',
            'plan' => $planTier->value,
        ];

        return Response::structured($payload);
    }
}
