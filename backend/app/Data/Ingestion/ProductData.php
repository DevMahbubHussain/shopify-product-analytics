<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Enums\ProductStatus;
use App\Support\IngestionRules;
use App\Support\ShopifyGid;
use Carbon\CarbonImmutable;

final readonly class ProductData
{
    /** @param list<VariantData> $variants */
    public function __construct(
        public ShopifyGid $gid,
        public string $title,
        public string $handle,
        public ?string $vendor,
        public ?string $productType,
        public ProductStatus $status,
        public ?string $imageUrl,
        public ?CarbonImmutable $publishedAt,
        public CarbonImmutable $shopifyUpdatedAt,
        public array $variants,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ShopifyGid::parse($data['gid'], 'Product'),
            $data['title'],
            $data['handle'],
            $data['vendor'] ?? null,
            $data['product_type'] ?? null,
            ProductStatus::from($data['status']),
            $data['image_url'] ?? null,
            IngestionRules::toUtc($data['published_at'] ?? null),
            IngestionRules::toUtc($data['shopify_updated_at']),
            array_map(VariantData::fromArray(...), $data['variants'] ?? []),
        );
    }
}
