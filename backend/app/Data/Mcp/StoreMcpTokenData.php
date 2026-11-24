<?php

declare(strict_types=1);

namespace App\Data\Mcp;

use Carbon\CarbonImmutable;

final readonly class StoreMcpTokenData
{
    public function __construct(
        public string $shopDomain,
        public string $name,
        public string $staffRef,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    /**
     * @param  array{shop_domain: string, name: string, staff_ref: string, expires_at?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            shopDomain: $data['shop_domain'],
            name: $data['name'],
            staffRef: $data['staff_ref'],
            expiresAt: isset($data['expires_at']) && $data['expires_at'] !== null
                ? CarbonImmutable::parse($data['expires_at'])
                : null,
        );
    }
}
