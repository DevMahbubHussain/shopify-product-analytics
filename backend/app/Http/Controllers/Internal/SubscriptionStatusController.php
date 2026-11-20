<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\PlanTier;
use App\Enums\ShopStatus;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Models\Shop;
use App\Services\PlanEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubscriptionStatusController
{
    public function __construct(
        private readonly PlanEntitlementService $planService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $shopDomain = (string) $request->attributes->get('shop_domain');
        $shop = Shop::query()->where('shop_domain', $shopDomain)->first() ?? throw new UnknownShop();

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop();
        }

        $tier = $this->planService->resolvePlan($shop);
        $activeSub = $tier === PlanTier::Free ? null : $shop->activeSubscription;

        return new JsonResponse([
            'plan_key' => $tier->value,
            'status' => $activeSub?->status,
            'max_analytics_days' => $tier->maxAnalyticsDays(),
            'can_export_csv' => $tier->canExportCsv(),
            'trial_ends_at' => $activeSub?->trial_ends_at?->toIso8601String(),
            'current_period_end' => $activeSub?->current_period_end?->toIso8601String(),
        ]);
    }
}
