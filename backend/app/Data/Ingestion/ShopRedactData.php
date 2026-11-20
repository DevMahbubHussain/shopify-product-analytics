<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

final readonly class ShopRedactData
{
    public function __construct(public string $shopDomain)
    {
    }

    /** @param array{shop_domain: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['shop_domain']);
    }
}
