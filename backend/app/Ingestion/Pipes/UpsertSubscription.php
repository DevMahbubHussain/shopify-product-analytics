<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\SubscriptionData;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\SubscriptionResult;
use App\Models\Subscription;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpsertSubscription
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $data = $context->payload(SubscriptionData::class);
        $shop = $context->shop();

        DB::transaction(function () use ($shop, $data): void {
            Subscription::query()->updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_subscription_gid' => $data->shopifySubscriptionGid?->__toString(),
                ],
                [
                    'plan_key' => $data->planKey,
                    'status' => $data->status,
                    'trial_ends_at' => $data->trialEndsAt,
                    'current_period_end' => $data->currentPeriodEnd,
                ],
            );
        }, 5);

        $context->response = new SubscriptionResult(
            status: 'recorded',
            planKey: $data->planKey,
            subscriptionStatus: $data->status,
        );

        return $next($context);
    }
}
