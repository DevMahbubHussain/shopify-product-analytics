<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\DecimalAmount;
use App\Support\ShopifyGid;

final readonly class VariantData
{
    public function __construct(
        public ShopifyGid $gid,
        public string $title,
        public ?string $sku,
        public ?DecimalAmount $price,
    ) {}

    /** @param array{gid: string, title: string, sku?: ?string, price?: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ShopifyGid::parse($data['gid'], 'ProductVariant'),
            $data['title'],
            $data['sku'] ?? null,
            DecimalAmount::parseNullable($data['price'] ?? null),
        );
    }
}
