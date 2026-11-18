<?php

declare(strict_types=1);

namespace App\Ingestion\Responses;

use App\Enums\ShopStatus;
use App\Ingestion\IngestionResponse;

final readonly class ShopResult implements IngestionResponse
{
    public function __construct(public string $shopDomain, public ShopStatus $shopStatus)
    {
    }

    public function status(): int
    {
        return 200;
    }

    /** @return array{shop_domain: string, status: string} */
    public function jsonSerialize(): array
    {
        return ['shop_domain' => $this->shopDomain, 'status' => $this->shopStatus->value];
    }
}
