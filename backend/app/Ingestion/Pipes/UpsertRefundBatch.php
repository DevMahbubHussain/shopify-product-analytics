<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Analytics\Services\StoreTimezoneService;
use App\Data\Ingestion\RefundBatch;
use App\Data\Ingestion\RefundLineData;
use App\Events\RefundsIngested;
use App\Ingestion\Exceptions\MissingOrders;
use App\Ingestion\GuardedUpsert;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\BatchResult;
use App\Ingestion\Services\OutboxService;
use App\Ingestion\TenantIdLookup;
use App\Support\ShopifyGid;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpsertRefundBatch
{
    public function __construct(
        private readonly GuardedUpsert $upsert,
        private readonly TenantIdLookup $lookup,
        private readonly StoreTimezoneService $timezoneService,
        private readonly OutboxService $outboxService,
    ) {}

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $batch = $context->payload(RefundBatch::class);
        $shopId = $context->shop()->id;
        $timezone = $context->shop()->timezone;

        $incomingRefundGids = array_map(fn (RefundLineData $l): string => (string) $l->refundLineItemGid, $batch->lines);

        $orderIds = $this->lookup->byGid('orders', 'shopify_order_gid', $shopId, array_map(fn (RefundLineData $l): ShopifyGid => $l->orderGid, $batch->lines));
        $this->assertOrdersKnown($batch, $orderIds);
        $lineItemIds = $this->lookup->byGid('order_line_items', 'shopify_line_item_gid', $shopId, array_map(fn (RefundLineData $l): ?ShopifyGid => $l->lineItemGid, $batch->lines));

        $result = DB::transaction(function () use (
            $batch,
            $shopId,
            $timezone,
            $orderIds,
            $lineItemIds,
            $incomingRefundGids
        ): array {
            $this->lockParentOrders($shopId, array_values($orderIds));

            $preUpsertBuckets = $this->captureBucketsByRefundGids($shopId, $incomingRefundGids, $timezone);

            $written = ($this->upsert)(
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
                    'shopify_updated_at' => $l->shopifyUpdatedAt ?? $l->processedAt,
                ], $batch->lines),
                ['shop_id', 'shopify_refund_line_item_gid'],
                [
                    'order_id',
                    'line_item_id',
                    'shopify_refund_gid',
                    'refunded_quantity',
                    'refunded_subtotal',
                    'currency_code',
                    'processed_at',
                    'shopify_updated_at',
                ],
                freshnessColumn: 'shopify_updated_at',
            );

            if ($written === []) {
                return ['written_count' => 0, 'event' => null, 'outbox_id' => null];
            }

            $writtenGids = array_flip(array_keys($written));
            $writtenLines = array_values(array_filter($batch->lines, fn (RefundLineData $l): bool => isset($writtenGids[(string) $l->refundLineItemGid])));

            $postUpsertBuckets = $this->captureBucketsByRefundGids($shopId, array_keys($written), $timezone);
            $affectedBuckets = $this->mergeBuckets($preUpsertBuckets, $postUpsertBuckets);
            $affectedOrderIds = array_values(array_unique(array_map(fn (RefundLineData $l): string => $orderIds[(string) $l->orderGid], $writtenLines)));

            $event = new RefundsIngested($shopId, $affectedOrderIds, $affectedBuckets);
            $outboxId = $this->outboxService->record($shopId, $event);

            return ['written_count' => count($written), 'event' => $event, 'outbox_id' => $outboxId];
        }, 5);

        if ($result['event'] !== null) {
            $context->events[] = $result['event'];
            if ($result['outbox_id'] !== null) {
                $context->outboxIds[] = $result['outbox_id'];
            }
        }

        $context->response = new BatchResult(count($batch->lines), $result['written_count']);

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

    /**
     * @param  list<string>  $orderIds
     */
    private function lockParentOrders(string $shopId, array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $uniqueIds = array_values(array_unique($orderIds));
        sort($uniqueIds);

        DB::table('orders')
            ->where('shop_id', $shopId)
            ->whereIn('id', $uniqueIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * @param  list<string>  $refundGids
     * @return array<string, array{product_id: string, metric_date: string}>
     */
    private function captureBucketsByRefundGids(string $shopId, array $refundGids, ?string $timezone): array
    {
        if ($refundGids === []) {
            return [];
        }

        $lines = DB::table('refund_line_items')
            ->join('order_line_items', function ($join): void {
                $join->on('order_line_items.id', '=', 'refund_line_items.line_item_id')
                    ->on('order_line_items.shop_id', '=', 'refund_line_items.shop_id');
            })
            ->where('refund_line_items.shop_id', $shopId)
            ->whereIn('refund_line_items.shopify_refund_line_item_gid', $refundGids)
            ->whereNotNull('order_line_items.product_id')
            ->whereNotNull('refund_line_items.processed_at')
            ->select([
                'order_line_items.product_id',
                'refund_line_items.processed_at',
            ])
            ->get();

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
     * @param  array<string, array{product_id: string, metric_date: string}>  $pre
     * @param  array<string, array{product_id: string, metric_date: string}>  $post
     * @return list<array{product_id: string, metric_date: string}>
     */
    private function mergeBuckets(array $pre, array $post): array
    {
        return array_values(array_merge($pre, $post));
    }
}
