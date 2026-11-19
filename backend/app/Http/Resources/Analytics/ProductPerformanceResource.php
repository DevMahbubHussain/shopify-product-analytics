<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Analytics\Domain\FinancialMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property object $resource
 */
final class ProductPerformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'shopify_product_gid' => (string) $this->resource->shopify_product_gid,
            'title' => (string) $this->resource->title,
            'handle' => (string) $this->resource->handle,
            'vendor' => $this->resource->vendor,
            'product_type' => $this->resource->product_type,
            'status' => (string) $this->resource->status,
            'image_url' => $this->resource->image_url,
            'gross_sales' => FinancialMath::formatDecimal((string) $this->resource->gross_sales),
            'discounts' => FinancialMath::formatDecimal((string) $this->resource->discounts),
            'refunds' => FinancialMath::formatDecimal((string) $this->resource->refunds),
            'net_sales' => FinancialMath::formatDecimal((string) $this->resource->net_sales),
            'units_sold' => (int) $this->resource->units_sold,
            'orders' => (int) $this->resource->orders,
        ];
    }
}
