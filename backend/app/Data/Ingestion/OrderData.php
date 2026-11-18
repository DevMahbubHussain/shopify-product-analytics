<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Enums\OrderFinancialStatus;
use App\Enums\OrderFulfillmentStatus;
use App\Support\IngestionRules;
use App\Support\ShopifyGid;
use Carbon\CarbonImmutable;

final readonly class OrderData
{
    /** @param list<LineItemData> $lineItems */
    public function __construct(
        public ShopifyGid $gid,
        public ?string $name,
        public ?OrderFinancialStatus $financialStatus,
        public ?OrderFulfillmentStatus $fulfillmentStatus,
        public string $currencyCode,
        public ?CarbonImmutable $processedAt,
        public ?CarbonImmutable $cancelledAt,
        public CarbonImmutable $shopifyUpdatedAt,
        public array $lineItems,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ShopifyGid::parse($data['gid'], 'Order'),
            $data['name'] ?? null,
            OrderFinancialStatus::tryFrom((string) ($data['financial_status'] ?? '')),
            OrderFulfillmentStatus::tryFrom((string) ($data['fulfillment_status'] ?? '')),
            $data['currency_code'],
            IngestionRules::toUtc($data['processed_at'] ?? null),
            IngestionRules::toUtc($data['cancelled_at'] ?? null),
            IngestionRules::toUtc($data['shopify_updated_at']),
            array_map(LineItemData::fromArray(...), $data['line_items']),
        );
    }
}
