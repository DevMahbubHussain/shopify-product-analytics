<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\ProductBatch;
use App\Data\Ingestion\ProductData;
use App\Ingestion\GuardedUpsert;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\BatchResult;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpsertProductBatch
{
    public function __construct(private readonly GuardedUpsert $upsert) {}

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $batch = $context->payload(ProductBatch::class);
        $shopId = $context->shop()->id;

        $written = DB::transaction(function () use ($batch, $shopId): int {
            $productIds = ($this->upsert)(
                'products',
                array_map(fn (ProductData $p): array => $this->productRow($shopId, $p), $batch->products),
                ['shop_id', 'shopify_product_gid'],
                ['title', 'handle', 'vendor', 'product_type', 'status', 'image_url', 'published_at', 'shopify_updated_at'],
                freshnessColumn: 'shopify_updated_at',
            );

            ($this->upsert)(
                'product_variants',
                $this->variantRows($shopId, $batch, $productIds),
                ['shop_id', 'shopify_variant_gid'],
                ['product_id', 'title', 'sku', 'price'],
            );

            return count($productIds);
        }, 5);

        $context->response = new BatchResult(count($batch->products), $written);

        return $next($context);
    }

    /** @return array<string, mixed> */
    private function productRow(string $shopId, ProductData $product): array
    {
        return [
            'shop_id' => $shopId,
            'shopify_product_gid' => $product->gid,
            'title' => $product->title,
            'handle' => $product->handle,
            'vendor' => $product->vendor,
            'product_type' => $product->productType,
            'status' => $product->status,
            'image_url' => $product->imageUrl,
            'published_at' => $product->publishedAt,
            'shopify_updated_at' => $product->shopifyUpdatedAt,
        ];
    }

    /**
     * Variants of stale (skipped) products are skipped with them.
     *
     * @param  array<string, string>  $productIds
     * @return list<array<string, mixed>>
     */
    private function variantRows(string $shopId, ProductBatch $batch, array $productIds): array
    {
        $rows = [];
        foreach ($batch->products as $product) {
            $productId = $productIds[(string) $product->gid] ?? null;
            if ($productId === null) {
                continue;
            }
            foreach ($product->variants as $variant) {
                $rows[] = [
                    'shop_id' => $shopId,
                    'product_id' => $productId,
                    'shopify_variant_gid' => $variant->gid,
                    'title' => $variant->title,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                ];
            }
        }

        return $rows;
    }
}
