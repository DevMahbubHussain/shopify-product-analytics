<?php

declare(strict_types=1);

namespace App\Ingestion\Responses;

use App\Ingestion\IngestionResponse;

final readonly class ShopRedactResult implements IngestionResponse
{
    public function __construct(public string $shopDomain) {}

    public function status(): int
    {
        return 200;
    }

    /** @return array{status: string, shop_domain: string} */
    public function jsonSerialize(): array
    {
        return [
            'status' => 'purged',
            'shop_domain' => $this->shopDomain,
        ];
    }
}
