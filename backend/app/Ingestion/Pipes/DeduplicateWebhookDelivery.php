<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\WebhookDeliveryData;
use App\Enums\WebhookEventStatus;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\WebhookDeliveryResult;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeduplicateWebhookDelivery
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $delivery = $context->payload(WebhookDeliveryData::class);

        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'shop_id' => $context->shop()->id,
            'webhook_id' => $delivery->webhookId,
            'topic' => $delivery->topic,
            'payload_hash' => $delivery->payloadHash,
            'status' => WebhookEventStatus::Received->value,
            'received_at' => $delivery->receivedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context->response = new WebhookDeliveryResult(duplicate: $inserted === 0);

        return $next($context);
    }
}
