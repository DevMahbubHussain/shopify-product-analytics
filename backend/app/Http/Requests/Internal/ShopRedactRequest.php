<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\ShopRedactData;

final class ShopRedactRequest extends IngestionRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['shop_domain' => $this->shopDomain()]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/'],
        ];
    }

    public function toDto(): ShopRedactData
    {
        /** @var array{shop_domain: string} $data */
        $data = $this->validated();

        return ShopRedactData::fromArray($data);
    }
}
