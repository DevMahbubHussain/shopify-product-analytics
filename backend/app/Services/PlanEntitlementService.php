<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanTier;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Shop;
use Carbon\CarbonImmutable;

final class PlanEntitlementService
{
    public function resolvePlan(Shop $shop): PlanTier
    {
        $activeSub = $shop->activeSubscription;

        if ($activeSub === null || strtoupper($activeSub->status) !== 'ACTIVE') {
            return PlanTier::Free;
        }

        return PlanTier::fromPlanKey($activeSub->plan_key);
    }

    public function enforceDateRangeLimit(Shop $shop, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $tier = $this->resolvePlan($shop);
        $maxDays = $tier->maxAnalyticsDays();

        $days = (int) $from->diffInDays($to);

        if ($days > $maxDays) {
            throw new PlanLimitExceededException(
                $tier,
                $maxDays,
                "The {$tier->value} plan allows querying up to {$maxDays} days of analytics history.",
            );
        }
    }

    public function canExportCsv(Shop $shop): bool
    {
        return $this->resolvePlan($shop)->canExportCsv();
    }

    public function enforceCsvExport(Shop $shop): void
    {
        if (! $this->canExportCsv($shop)) {
            throw new PlanLimitExceededException(
                PlanTier::Free,
                PlanTier::Free->maxAnalyticsDays(),
                'CSV export requires an active Pro subscription.',
            );
        }
    }
}
