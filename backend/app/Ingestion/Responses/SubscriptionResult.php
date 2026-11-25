<?php

declare(strict_types=1);

namespace App\Ingestion\Responses;

use App\Ingestion\IngestionResponse;

final readonly class SubscriptionResult implements IngestionResponse
{
    public function __construct(
        public string $status,
        public string $planKey,
        public string $subscriptionStatus,
    ) {}

    public function status(): int
    {
        return 200;
    }

    /** @return array{status: string, plan_key: string, subscription_status: string} */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'plan_key' => $this->planKey,
            'subscription_status' => $this->subscriptionStatus,
        ];
    }
}
