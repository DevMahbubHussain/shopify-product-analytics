<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\ProductBatch;
use App\Enums\ProductStatus;
use App\Support\IngestionRules;
use Illuminate\Validation\Rule;

final class IngestProductsRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.gid' => [...IngestionRules::gid('Product'), 'distinct'],
            'products.*.title' => ['required', 'string', 'max:255'],
            'products.*.handle' => ['required', 'string', 'max:255'],
            'products.*.vendor' => ['nullable', 'string', 'max:128'],
            'products.*.product_type' => ['nullable', 'string', 'max:128'],
            'products.*.status' => ['required', Rule::enum(ProductStatus::class)],
            'products.*.image_url' => ['nullable', 'string', 'max:2048'],
            'products.*.published_at' => IngestionRules::timestamp(required: false),
            'products.*.shopify_updated_at' => IngestionRules::timestamp(),
            'products.*.variants' => ['present', 'array', 'max:2048'],
            'products.*.variants.*.gid' => [...IngestionRules::gid('ProductVariant'), 'distinct'],
            'products.*.variants.*.title' => ['required', 'string', 'max:255'],
            'products.*.variants.*.sku' => ['nullable', 'string', 'max:128'],
            'products.*.variants.*.price' => IngestionRules::amount(required: false),
        ];
    }

    public function toDto(): ProductBatch
    {
        /** @var array{products: list<array<string, mixed>>} $data */
        $data = $this->validated();

        return ProductBatch::fromArray($data);
    }
}
