<?php

declare(strict_types=1);

namespace App\Ingestion\Responses;

use App\Ingestion\IngestionResponse;

final readonly class WebhookDeliveryResult implements IngestionResponse
{
    public function __construct(public bool $duplicate) {}

    public function status(): int
    {
        return 200;
    }

    /** @return array{duplicate: bool} */
    public function jsonSerialize(): array
    {
        return ['duplicate' => $this->duplicate];
    }
}
