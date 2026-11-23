<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Analytics\Services\StoreTimezoneService;
use App\Data\Ingestion\LineItemData;
use App\Data\Ingestion\OrderBatch;
use App\Data\Ingestion\OrderData;
use App\Events\OrdersIngested;
use App\Ingestion\GuardedUpsert;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\BatchResult;
use App\Ingestion\Services\OutboxService;
use App\Ingestion\TenantIdLookup;
use App\Support\ShopifyGid;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpsertOrderBatch
{
    public function __construct(
        private readonly GuardedUpsert $upsert,
        private readonly TenantIdLookup $lookup,
        private readonly StoreTimezoneService $timezoneService,
        private readonly OutboxService $outboxService,
    ) {
    }

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $batch = $context->payload(OrderBatch::class);
        $shopId = $context->shop()->id;
        $timezone = $context->shop()->timezone;

        $incomingGids = array_map(fn (OrderData $o): string => (string) $o->gid, $batch->orders);
        $preUpsertBuckets = $this->captureBucketsByOrderGids($shopId, $incomingGids, $timezone);
        $preExistingTimestamps = $this->captureOrderTimestampsByGids($shopId, $incomingGids);

        $result = DB::transaction(function () use ($batch, $shopId, $timezone, $preUpsertBuckets, $preExistingTimestamps): array {
            $orderIds = ($this->upsert)(
                'orders',
                array_map(fn (OrderData $o): array => $this->orderRow($shopId, $o), $batch->orders),
                ['shop_id', 'shopify_order_gid'],
                ['order_name', 'financial_status', 'fulfillment_status', 'currency_code', 'processed_at', 'cancelled_at', 'shopify_updated_at'],
                freshnessColumn: 'shopify_updated_at',
            );

            $writtenOrders = array_values(array_filter($batch->orders, fn (OrderData $o): bool => isset($orderIds[(string) $o->gid])));

            $this->pruneRemovedLineItems($shopId, $writtenOrders, $orderIds, $preExistingTimestamps);

            ($this->upsert)(
                'order_line_items',
                $this->lineItemRows($shopId, $writtenOrders, $orderIds),
                ['shop_id', 'shopify_line_item_gid'],
                ['order_id', 'product_id', 'variant_id', 'title_snapshot', 'sku_snapshot', 'quantity', 'original_unit_price', 'discount_amount', 'currency_code'],
            );

            $writtenIds = array_values(array_map(fn (OrderData $o): string => $orderIds[(string) $o->gid], $writtenOrders));

            if ($writtenIds === []) {
                return ['order_ids' => [], 'event' => null, 'outbox_id' => null];
            }

            $postUpsertBuckets = $this->captureBucketsByOrderIds($shopId, $writtenIds, $timezone);
            $affectedBuckets = $this->mergeBuckets($preUpsertBuckets, $postUpsertBuckets);

            $event = new OrdersIngested($shopId, $writtenIds, $affectedBuckets);
            $outboxId = $this->outboxService->record($shopId, $event);

            return ['order_ids' => $writtenIds, 'event' => $event, 'outbox_id' => $outboxId];
        }, 5);

        if ($result['event'] !== null) {
            $context->events[] = $result['event'];
            if ($result['outbox_id'] !== null) {
                $context->outboxIds[] = $result['outbox_id'];
            }
        }

        $context->response = new BatchResult(count($batch->orders), count($result['order_ids']));

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

    /**
     * @param list<OrderData> $writtenOrders
     * @param array<string, string> $orderIds
     * @param array<string, ?CarbonImmutable> $preExistingOrderTimestamps
     */
    private function pruneRemovedLineItems(
        string $shopId,
        array $writtenOrders,
        array $orderIds,
        array $preExistingOrderTimestamps
    ): void {
        $updatedOrderIds = [];
        $incomingLineGids = [];

        foreach ($writtenOrders as $order) {
            $gid = (string) $order->gid;
            $existingTimestamp = $preExistingOrderTimestamps[$gid] ?? null;

            if ($existingTimestamp !== null && $order->shopifyUpdatedAt->greaterThan($existingTimestamp)) {
                $orderId = $orderIds[$gid] ?? null;
                if ($orderId !== null) {
                    $updatedOrderIds[] = $orderId;
                    foreach ($order->lineItems as $line) {
                        $incomingLineGids[] = (string) $line->gid;
                    }
                }
            }
        }

        if ($updatedOrderIds === []) {
            return;
        }

        $query = DB::table('order_line_items')
            ->where('shop_id', $shopId)
            ->whereIn('order_id', $updatedOrderIds);

        if ($incomingLineGids !== []) {
            $query->whereNotIn('shopify_line_item_gid', $incomingLineGids);
        }

        $query->delete();
    }

    /**
     * @param list<string> $orderGids
     * @return array<string, ?CarbonImmutable>
     */
    private function captureOrderTimestampsByGids(string $shopId, array $orderGids): array
    {
        if ($orderGids === []) {
            return [];
        }

        return DB::table('orders')
            ->where('shop_id', $shopId)
            ->whereIn('shopify_order_gid', $orderGids)
            ->pluck('shopify_updated_at', 'shopify_order_gid')
            ->map(fn ($ts): ?CarbonImmutable => $ts !== null ? CarbonImmutable::parse($ts) : null)
            ->all();
    }

    /**
     * @param list<string> $orderGids
     * @return array<string, array{product_id: string, metric_date: string}>
     */
    private function captureBucketsByOrderGids(string $shopId, array $orderGids, ?string $timezone): array
    {
        if ($orderGids === []) {
            return [];
        }

        $lines = DB::table('order_line_items')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'order_line_items.order_id')
                    ->on('orders.shop_id', '=', 'order_line_items.shop_id');
            })
            ->where('order_line_items.shop_id', $shopId)
            ->whereIn('orders.shopify_order_gid', $orderGids)
            ->whereNotNull('order_line_items.product_id')
            ->whereNotNull('orders.processed_at')
            ->select([
                'order_line_items.product_id',
                'orders.processed_at',
            ])
            ->get();

        return $this->formatBuckets($lines, $timezone);
    }

    /**
     * @param list<string> $orderIds
     * @return array<string, array{product_id: string, metric_date: string}>
     */
    private function captureBucketsByOrderIds(string $shopId, array $orderIds, ?string $timezone): array
    {
        if ($orderIds === []) {
            return [];
        }

        $lines = DB::table('order_line_items')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'order_line_items.order_id')
                    ->on('orders.shop_id', '=', 'order_line_items.shop_id');
            })
            ->where('order_line_items.shop_id', $shopId)
            ->whereIn('orders.id', $orderIds)
            ->whereNotNull('order_line_items.product_id')
            ->whereNotNull('orders.processed_at')
            ->select([
                'order_line_items.product_id',
                'orders.processed_at',
            ])
            ->get();

        return $this->formatBuckets($lines, $timezone);
    }

    /**
     * @param iterable<object> $lines
     * @return array<string, array{product_id: string, metric_date: string}>
     */
    private function formatBuckets(iterable $lines, ?string $timezone): array
    {
        $buckets = [];
        foreach ($lines as $line) {
            $productId = (string) $line->product_id;
            $metricDate = $this->timezoneService->toStoreDate($line->processed_at, $timezone);
            $key = "{$productId}:{$metricDate}";
            $buckets[$key] = [
                'product_id' => $productId,
                'metric_date' => $metricDate,
            ];
        }

        return $buckets;
    }

    /**
     * @param array<string, array{product_id: string, metric_date: string}> $pre
     * @param array<string, array{product_id: string, metric_date: string}> $post
     * @return list<array{product_id: string, metric_date: string}>
     */
    private function mergeBuckets(array $pre, array $post): array
    {
        return array_values(array_merge($pre, $post));
    }
}
