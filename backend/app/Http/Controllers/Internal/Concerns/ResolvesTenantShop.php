<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Concerns;

use App\Enums\ShopStatus;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Models\Shop;
use Illuminate\Http\Request;

trait ResolvesTenantShop
{
    protected function resolveShop(Request $request): Shop
    {
        $shopDomain = (string) $request->attributes->get('shop_domain');
        /**
         * @var Shop|null $shop
         */
        $shop = Shop::query()->where('shop_domain', $shopDomain)->first() ?? throw new UnknownShop;

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop;
        }

        return $shop;
    }
}
