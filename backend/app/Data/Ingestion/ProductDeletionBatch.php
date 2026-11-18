<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\ShopifyGid;

final readonly class ProductDeletionBatch
{
    /** @param list<ShopifyGid> $productGids */
    public function __construct(public array $productGids)
    {
    }

    /** @param array{product_gids: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            fn (string $gid): ShopifyGid => ShopifyGid::parse($gid, 'Product'),
            $data['product_gids'],
        ));
    }

    /** @return list<string> */
    public function gidStrings(): array
    {
        return array_map(fn (ShopifyGid $gid): string => (string) $gid, $this->productGids);
    }
}
