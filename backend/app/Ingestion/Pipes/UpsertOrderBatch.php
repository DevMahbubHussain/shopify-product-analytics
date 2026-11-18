<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\LineItemData;
use App\Data\Ingestion\OrderBatch;
use App\Data\Ingestion\OrderData;
use App\Events\OrdersIngested;
use App\Ingestion\GuardedUpsert;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\BatchResult;
use App\Ingestion\TenantIdLookup;
use App\Support\ShopifyGid;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpsertOrderBatch
{
    public function __construct(
        private readonly GuardedUpsert $upsert,
        private readonly TenantIdLookup $lookup,
    ) {
    }

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $batch = $context->payload(OrderBatch::class);
        $shopId = $context->shop()->id;

        $orderIds = DB::transaction(function () use ($batch, $shopId): array {
            $orderIds = ($this->upsert)(
                'orders',
                array_map(fn (OrderData $o): array => $this->orderRow($shopId, $o), $batch->orders),
                ['shop_id', 'shopify_order_gid'],
                ['order_name', 'financial_status', 'fulfillment_status', 'currency_code', 'processed_at', 'cancelled_at', 'shopify_updated_at'],
                freshnessColumn: 'shopify_updated_at',
            );

            $writtenOrders = array_values(array_filter($batch->orders, fn (OrderData $o): bool => isset($orderIds[(string) $o->gid])));

            ($this->upsert)(
                'order_line_items',
                $this->lineItemRows($shopId, $writtenOrders, $orderIds),
                ['shop_id', 'shopify_line_item_gid'],
                ['order_id', 'product_id', 'variant_id', 'title_snapshot', 'sku_snapshot', 'quantity', 'original_unit_price', 'discount_amount', 'currency_code'],
            );

            return array_values(array_map(fn (OrderData $o): string => $orderIds[(string) $o->gid], $writtenOrders));
        }, 5);

        if ($orderIds !== []) {
            $context->events[] = new OrdersIngested($shopId, $orderIds);
        }

        $context->response = new BatchResult(count($batch->orders), count($orderIds));

        return $next($context);
    }

    /** @return array<string, mixed> */
    private function orderRow(string $shopId, OrderData $order): array
    {
        return [
            'shop_id' => $shopId,
            'shopify_order_gid' => $order->gid,
            'order_name' => $order->name,
            'financial_status' => $order->financialStatus,
            'fulfillment_status' => $order->fulfillmentStatus,
            'currency_code' => $order->currencyCode,
            'processed_at' => $order->processedAt,
            'cancelled_at' => $order->cancelledAt,
            'shopify_updated_at' => $order->shopifyUpdatedAt,
        ];
    }

    /**
     * @param list<OrderData> $orders
     * @param array<string, string> $orderIds
     * @return list<array<string, mixed>>
     */
    private function lineItemRows(string $shopId, array $orders, array $orderIds): array
    {
        $lines = array_merge(...array_map(fn (OrderData $o): array => $o->lineItems, $orders));
        $productIds = $this->lookup->byGid('products', 'shopify_product_gid', $shopId, array_map(fn (LineItemData $l): ?ShopifyGid => $l->productGid, $lines));
        $variantIds = $this->lookup->byGid('product_variants', 'shopify_variant_gid', $shopId, array_map(fn (LineItemData $l): ?ShopifyGid => $l->variantGid, $lines));

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->lineItems as $line) {
                $rows[] = [
                    'shop_id' => $shopId,
                    'order_id' => $orderIds[(string) $order->gid],
                    'product_id' => $productIds[(string) $line->productGid] ?? null,
                    'variant_id' => $variantIds[(string) $line->variantGid] ?? null,
                    'shopify_line_item_gid' => $line->gid,
                    'title_snapshot' => $line->title,
                    'sku_snapshot' => $line->sku,
                    'quantity' => $line->quantity,
                    'original_unit_price' => $line->originalUnitPrice,
                    'discount_amount' => $line->discountAmount,
                    'currency_code' => $order->currencyCode,
                ];
            }
        }

        return $rows;
    }
}
