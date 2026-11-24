<?php

declare(strict_types=1);

namespace App\Data\Mcp;

final readonly class McpConsentData
{
    public function __construct(
        public string $shopDomain,
        public string $staffRef,
        public string $policyVersion,
    ) {}

    /**
     * @param  array{shop_domain: string, staff_ref: string, policy_version: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            shopDomain: $data['shop_domain'],
            staffRef: $data['staff_ref'],
            policyVersion: $data['policy_version'],
        );
    }
}
