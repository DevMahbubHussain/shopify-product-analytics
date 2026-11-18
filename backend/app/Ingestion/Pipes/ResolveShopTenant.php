<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Enums\ShopStatus;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Models\Shop;
use Closure;

final class ResolveShopTenant
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $shop = Shop::query()->where('shop_domain', $context->shopDomain)->first() ?? throw new UnknownShop();

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop();
        }

        $context->shop = $shop;

        return $next($context);
    }
}
