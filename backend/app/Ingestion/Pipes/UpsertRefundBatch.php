<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\RefundBatch;
use App\Data\Ingestion\RefundLineData;
use App\Events\RefundsIngested;
use App\Ingestion\Exceptions\MissingOrders;
use App\Ingestion\GuardedUpsert;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\BatchResult;
use App\Ingestion\TenantIdLookup;
use App\Support\ShopifyGid;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpsertRefundBatch
{
    public function __construct(
        private readonly GuardedUpsert $upsert,
        private readonly TenantIdLookup $lookup,
    ) {
    }

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $batch = $context->payload(RefundBatch::class);
        $shopId = $context->shop()->id;

        $orderIds = $this->lookup->byGid('orders', 'shopify_order_gid', $shopId, array_map(fn (RefundLineData $l): ShopifyGid => $l->orderGid, $batch->lines));
        $this->assertOrdersKnown($batch, $orderIds);
        $lineItemIds = $this->lookup->byGid('order_line_items', 'shopify_line_item_gid', $shopId, array_map(fn (RefundLineData $l): ?ShopifyGid => $l->lineItemGid, $batch->lines));

        $written = DB::transaction(fn (): array => ($this->upsert)(
            'refund_line_items',
            array_map(fn (RefundLineData $l): array => [
                'shop_id' => $shopId,
                'order_id' => $orderIds[(string) $l->orderGid],
                'line_item_id' => $lineItemIds[(string) $l->lineItemGid] ?? null,
                'shopify_refund_gid' => $l->refundGid,
                'shopify_refund_line_item_gid' => $l->refundLineItemGid,
                'refunded_quantity' => $l->quantity,
                'refunded_subtotal' => $l->subtotal,
                'currency_code' => $l->currencyCode,
                'processed_at' => $l->processedAt,
            ], $batch->lines),
            ['shop_id', 'shopify_refund_line_item_gid'],
            ['order_id', 'line_item_id', 'shopify_refund_gid', 'refunded_quantity', 'refunded_subtotal', 'currency_code', 'processed_at'],
        ), 5);

        $affectedOrderIds = array_values(array_unique(array_map(fn (RefundLineData $l): string => $orderIds[(string) $l->orderGid], $batch->lines)));
        $context->events[] = new RefundsIngested($shopId, $affectedOrderIds);
        $context->response = new BatchResult(count($batch->lines), count($written));

        return $next($context);
    }

    /** @param array<string, string> $orderIds */
    private function assertOrdersKnown(RefundBatch $batch, array $orderIds): void
    {
        $missing = array_values(array_unique(array_filter(
            array_map(fn (RefundLineData $l): string => (string) $l->orderGid, $batch->lines),
            fn (string $gid): bool => ! isset($orderIds[$gid]),
        )));

        if ($missing !== []) {
            sort($missing);

            throw new MissingOrders($missing);
        }
    }
}
