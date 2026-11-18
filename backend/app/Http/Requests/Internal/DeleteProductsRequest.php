<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\ProductDeletionBatch;
use App\Support\IngestionRules;

final class DeleteProductsRequest extends IngestionRequest
{
    public const MAX_PRODUCTS = 250;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_gids' => ['required', 'array', 'list', 'min:1', 'max:' . self::MAX_PRODUCTS],
            'product_gids.*' => [...IngestionRules::gid('Product'), 'distinct'],
        ];
    }

    public function toDto(): ProductDeletionBatch
    {
        /** @var array{product_gids: list<string>} $data */
        $data = $this->validated();

        return ProductDeletionBatch::fromArray($data);
    }
}
