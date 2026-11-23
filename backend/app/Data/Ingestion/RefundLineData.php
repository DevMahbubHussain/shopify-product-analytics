<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Support\DecimalAmount;
use App\Support\IngestionRules;
use App\Support\ShopifyGid;
use Carbon\CarbonImmutable;

final readonly class RefundLineData
{
    public function __construct(
        public ShopifyGid $refundGid,
        public ShopifyGid $refundLineItemGid,
        public ShopifyGid $orderGid,
        public ?ShopifyGid $lineItemGid,
        public int $quantity,
        public DecimalAmount $subtotal,
        public string $currencyCode,
        public ?CarbonImmutable $processedAt,
        public ?CarbonImmutable $shopifyUpdatedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $processedAt = IngestionRules::toUtc($data['processed_at'] ?? null);
        $shopifyUpdatedAt = IngestionRules::toUtc($data['shopify_updated_at'] ?? null) ?? $processedAt;

        return new self(
            ShopifyGid::parse($data['refund_gid'], 'Refund'),
            ShopifyGid::parse($data['refund_line_item_gid'], 'RefundLineItem'),
            ShopifyGid::parse($data['order_gid'], 'Order'),
            ShopifyGid::parseNullable($data['line_item_gid'] ?? null, 'LineItem'),
            (int) $data['quantity'],
            DecimalAmount::parse($data['subtotal']),
            $data['currency_code'],
            $processedAt,
            $shopifyUpdatedAt,
        );
    }
}
