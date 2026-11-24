<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Enums\PlanTier;
use App\Enums\SyncRunStatus;
use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use App\Models\SyncRun;
use App\Services\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('shop_context')]
#[Title('Shop Context')]
#[Uri('analytics://shop/context')]
#[MimeType('application/json')]
#[Description('Store analytics configuration, active plan limits, currency, timezone, and historical data boundaries.')]
final class ShopContextResource extends Resource
{
    use FormatsToolMetadata;

    public function handle(Request $request): Response
    {
        $shop = $this->resolveCurrentShop(request());

        /**
         * @var PlanTier|null $planTier
         */
        $planTier = request()->attributes->get('mcp_plan_tier') ?? app(PlanEntitlementService::class)->resolvePlan($shop);

        /**
         * @var SyncRun|null $latestCompletedSync
         */
        $latestCompletedSync = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        $historyStartDate = $this->resolveHistoryStartDate($shop);
        $today = CarbonImmutable::now($shop->timezone ?? 'UTC')->toDateString();

        $payload = [
            'currency_code' => $shop->currency_code,
            'timezone' => $shop->timezone ?? 'UTC',
            'plan' => $planTier->value,
            'max_history_days' => $planTier->maxAnalyticsDays(),
            'history_start_date' => $historyStartDate,
            'history_end_date' => $today,
            'data_complete' => $latestCompletedSync !== null,
            'last_synced_at' => $latestCompletedSync?->completed_at?->toIso8601String(),
        ];

        return Response::json($payload);
    }
}
