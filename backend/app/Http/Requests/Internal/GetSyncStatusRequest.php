<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\ShopStatus;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Models\Shop;

final class GetSyncStatusRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function resolveShop(): Shop
    {
        $shop = Shop::query()->where('shop_domain', $this->shopDomain())->first() ?? throw new UnknownShop();

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop();
        }

        return $shop;
    }
}
