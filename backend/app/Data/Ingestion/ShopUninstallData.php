<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use Carbon\CarbonImmutable;

final readonly class ShopUninstallData
{
    public function __construct(
        public string $shopDomain,
        public CarbonImmutable $uninstalledAt,
    ) {}

    /** @param array{uninstalled_at: string} $data */
    public static function fromArray(string $shopDomain, array $data): self
    {
        return new self($shopDomain, CarbonImmutable::parse($data['uninstalled_at'])->utc());
    }
}
