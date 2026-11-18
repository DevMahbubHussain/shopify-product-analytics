<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

final readonly class ProductBatch
{
    /** @param list<ProductData> $products */
    public function __construct(public array $products)
    {
    }

    /** @param array{products: list<array<string, mixed>>} $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(ProductData::fromArray(...), $data['products']));
    }
}
