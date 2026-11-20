<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\SubscriptionData;
use App\Support\IngestionRules;

final class IngestSubscriptionRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shopify_subscription_gid' => IngestionRules::gid('AppSubscription', required: false),
            'plan_key' => ['required', 'string', 'max:32'],
            'status' => ['required', 'string', 'max:32'],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_end' => ['nullable', 'date'],
        ];
    }

    public function toDto(): SubscriptionData
    {
        /** @var array{shopify_subscription_gid?: ?string, plan_key: string, status: string, trial_ends_at?: ?string, current_period_end?: ?string} $data */
        $data = $this->validated();

        return SubscriptionData::fromArray($data);
    }
}
