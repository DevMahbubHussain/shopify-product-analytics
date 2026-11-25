<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\ProductDeletionBatch;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\BatchResult;
use Closure;
use Illuminate\Support\Facades\DB;

final class SoftDeleteProducts
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $batch = $context->payload(ProductDeletionBatch::class);
        $shopId = $context->shop()->id;

        $deleted = DB::transaction(function () use ($batch, $shopId): int {
            $productIds = $this->deleteProducts($shopId, $batch->gidStrings());

            if ($productIds !== []) {
                DB::table('product_variants')
                    ->where('shop_id', $shopId)
                    ->whereIn('product_id', $productIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            return count($productIds);
        }, 5);

        $context->response = new BatchResult(count($batch->productGids), $deleted);

        return $next($context);
    }

    /**
     * @param  list<string>  $gids
     * @return list<string> ids of products deleted by this call
     */
    private function deleteProducts(string $shopId, array $gids): array
    {
        $placeholders = implode(', ', array_fill(0, count($gids), '?'));

        $rows = DB::select(
            "update products set deleted_at = now(), updated_at = now()
             where shop_id = ? and shopify_product_gid in ({$placeholders}) and deleted_at is null
             returning id",
            [$shopId, ...$gids],
        );

        return array_map(fn (object $row): string => (string) $row->id, $rows);
    }
}
