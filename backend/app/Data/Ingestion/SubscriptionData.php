<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\ShopifyGid;
use Carbon\CarbonImmutable;

final readonly class SubscriptionData
{
    public function __construct(
        public ?ShopifyGid $shopifySubscriptionGid,
        public string $planKey,
        public string $status,
        public ?CarbonImmutable $trialEndsAt,
        public ?CarbonImmutable $currentPeriodEnd,
    ) {}

    /**
     * @param array{
     *     shopify_subscription_gid?: ?string,
     *     plan_key: string,
     *     status: string,
     *     trial_ends_at?: ?string,
     *     current_period_end?: ?string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ShopifyGid::parseNullable($data['shopify_subscription_gid'] ?? null, 'AppSubscription'),
            $data['plan_key'],
            $data['status'],
            isset($data['trial_ends_at']) && $data['trial_ends_at'] !== null ? CarbonImmutable::parse($data['trial_ends_at']) : null,
            isset($data['current_period_end']) && $data['current_period_end'] !== null ? CarbonImmutable::parse($data['current_period_end']) : null,
        );
    }
}
