<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\ShopifyGid;

final readonly class ShopData
{
    public function __construct(
        public string $shopDomain,
        public ?ShopifyGid $shopifyShopGid,
        public string $currencyCode,
        public string $timezone,
    ) {
    }

    /** @param array{shopify_shop_gid?: ?string, currency_code: string, timezone: string} $data */
    public static function fromArray(string $shopDomain, array $data): self
    {
        return new self(
            $shopDomain,
            ShopifyGid::parseNullable($data['shopify_shop_gid'] ?? null, 'Shop'),
            $data['currency_code'],
            $data['timezone'],
        );
    }
}
