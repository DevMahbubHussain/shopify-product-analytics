<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\IngestionRules;
use Carbon\CarbonImmutable;

final readonly class WebhookDeliveryData
{
    public function __construct(
        public string $webhookId,
        public string $topic,
        public string $payloadHash,
        public CarbonImmutable $receivedAt,
    ) {}

    /** @param array{webhook_id: string, topic: string, payload_hash: string, received_at: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['webhook_id'], $data['topic'], $data['payload_hash'], IngestionRules::toUtc($data['received_at']));
    }
}
