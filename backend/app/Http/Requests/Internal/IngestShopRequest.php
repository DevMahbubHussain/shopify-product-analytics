<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\ShopData;
use App\Support\IngestionRules;

final class IngestShopRequest extends IngestionRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['shop_domain' => $this->shopDomain()]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/'],
            'shopify_shop_gid' => IngestionRules::gid('Shop', required: false),
            'currency_code' => IngestionRules::currency(),
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
        ];
    }

    public function toDto(): ShopData
    {
        /** @var array{shopify_shop_gid?: ?string, currency_code: string, timezone: string} $data */
        $data = $this->validated();

        return ShopData::fromArray($this->shopDomain(), $data);
    }
}
