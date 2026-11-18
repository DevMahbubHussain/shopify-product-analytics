<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\WebhookDeliveryData;
use App\Support\IngestionRules;

final class RecordWebhookDeliveryRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'webhook_id' => ['required', 'string', 'max:128'],
            'topic' => ['required', 'string', 'max:128', 'regex:/^[a-z_]+\/[a-z_]+$/'],
            'payload_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'received_at' => IngestionRules::timestamp(),
        ];
    }

    public function toDto(): WebhookDeliveryData
    {
        /** @var array{webhook_id: string, topic: string, payload_hash: string, received_at: string} $data */
        $data = $this->validated();

        return WebhookDeliveryData::fromArray($data);
    }
}
