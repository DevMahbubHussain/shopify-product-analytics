<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\DecimalAmount;
use App\Support\ShopifyGid;

final readonly class LineItemData
{
    public function __construct(
        public ShopifyGid $gid,
        public ?ShopifyGid $productGid,
        public ?ShopifyGid $variantGid,
        public string $title,
        public ?string $sku,
        public int $quantity,
        public DecimalAmount $originalUnitPrice,
        public DecimalAmount $discountAmount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ShopifyGid::parse($data['gid'], 'LineItem'),
            ShopifyGid::parseNullable($data['product_gid'] ?? null, 'Product'),
            ShopifyGid::parseNullable($data['variant_gid'] ?? null, 'ProductVariant'),
            $data['title'],
            $data['sku'] ?? null,
            (int) $data['quantity'],
            DecimalAmount::parse($data['original_unit_price']),
            DecimalAmount::parse($data['discount_amount'] ?? '0'),
        );
    }
}
