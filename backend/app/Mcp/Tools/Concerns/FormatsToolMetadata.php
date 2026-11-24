<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Enums\PlanTier;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Response;

trait FormatsToolMetadata
{
    protected function resolveCurrentShop(Request $request): Shop
    {
        /**
         * @var Shop|null $shop
         */
        $shop = $request->attributes->get('mcp_shop');

        if ($shop === null) {
            throw new \RuntimeException('MCP shop tenant is not resolved on the current request.');
        }

        return $shop;
    }

    protected function resolveHistoryStartDate(Shop $shop): ?string
    {
        /** @var SyncRun|null $syncRun */
        $syncRun = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->where('sync_type', SyncType::Initial)
            ->orderByDesc('completed_at')
            ->first() ?? SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        $candidates = [];

        if ($syncRun !== null && is_array($syncRun->cursor_metadata) && ! empty($syncRun->cursor_metadata['window_start'])) {
            try {
                $candidates[] = CarbonImmutable::parse((string) $syncRun->cursor_metadata['window_start'])
                    ->setTimezone($shop->timezone ?? 'UTC')
                    ->toDateString();
            } catch (\Throwable) {
                // fallback below
            }
        } elseif ($shop->installed_at !== null) {
            $candidates[] = CarbonImmutable::instance($shop->installed_at)
                ->setTimezone($shop->timezone ?? 'UTC')
                ->subDays(60)
                ->toDateString();
        }

        $minMetricDate = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->min('metric_date');

        if ($minMetricDate !== null) {
            $candidates[] = (string) $minMetricDate;
        }

        if (empty($candidates)) {
            return null;
        }

        return min($candidates);
    }

    /**
     * @return array{
     *     currency_code: string,
     *     timezone: string,
     *     from: string|null,
     *     to: string|null,
     *     last_synced_at: string|null,
     *     data_complete: bool,
     *     plan: string
     * }
     */
    protected function buildMetadata(Shop $shop, ?string $from = null, ?string $to = null): array
    {
        /**
         * @var PlanTier|null $planTier
         */
        $planTier = request()->attributes->get('mcp_plan_tier');
        if ($planTier === null) {
            $planTier = app(PlanEntitlementService::class)->resolvePlan($shop);
        }

        /**
         * @var SyncRun|null $latestCompletedSync
         */
        $latestCompletedSync = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        $dataComplete = true;
        if ($latestCompletedSync === null) {
            $dataComplete = false;
        }

        if ($dataComplete && $from !== null) {
            $historyStartDate = $this->resolveHistoryStartDate($shop);
            if ($historyStartDate !== null && $from < $historyStartDate) {
                $dataComplete = false;
            }
        }

        return [
            'currency_code' => $shop->currency_code,
            'timezone' => $shop->timezone ?? 'UTC',
            'from' => $from,
            'to' => $to,
            'last_synced_at' => $latestCompletedSync?->completed_at?->toIso8601String(),
            'data_complete' => $dataComplete,
            'plan' => $planTier->value,
        ];
    }

    /**
     * @return array{0: string, 1: string}|Response
     */
    protected function validateDateRange(Shop $shop, string $from, string $to): array|Response
    {
        if ($from > $to) {
            return Response::error("Invalid date range: 'from' date ({$from}) cannot be after 'to' date ({$to}).");
        }

        /**
         * @var PlanTier $tier
         */
        $tier = request()->attributes->get('mcp_plan_tier') ?? app(PlanEntitlementService::class)->resolvePlan($shop);
        $maxDays = $tier->maxAnalyticsDays();

        $fromDate = CarbonImmutable::parse($from);
        $toDate = CarbonImmutable::parse($to);
        $daysDiff = (int) $fromDate->diffInDays($toDate) + 1;

        if ($daysDiff > $maxDays) {
            return Response::error(
                "Requested date range ({$daysDiff} days) exceeds the {$tier->value} plan limit of {$maxDays} days. Please select a shorter date range or upgrade to Pro."
            );
        }

        $today = CarbonImmutable::now($shop->timezone ?? 'UTC')->toDateString();
        if ($from > $today || $to > $today) {
            return Response::error("The requested date range ({$from} to {$to}) includes future dates (store today is {$today}). Historical analytics are only available for past or current dates.");
        }

        $hasCompletedSync = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->exists();

        if (! $hasCompletedSync && ! DB::table('product_daily_metrics')->where('shop_id', $shop->id)->exists() && ! DB::table('orders')->where('shop_id', $shop->id)->exists()) {
            return Response::error("Data sync has not completed for this store. Historical analytics are unavailable until the initial sync finishes. Call the 'get_sync_status' tool to monitor progress.");
        }

        $historyStartDate = $this->resolveHistoryStartDate($shop);

        if ($historyStartDate !== null && $to < $historyStartDate) {
            return Response::error("The requested date range ({$from} to {$to}) is earlier than the store's imported history. Earliest available data is {$historyStartDate}. Call get_sync_status for details.");
        }

        return [$from, $to];
    }
}
