<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\ShopData;
use App\Enums\ShopStatus;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\ShopResult;
use App\Models\Shop;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpsertShop
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $data = $context->payload(ShopData::class);

        DB::transaction(function () use ($data): void {
            $inserted = DB::table('shops')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'shop_domain' => $data->shopDomain,
                'shopify_shop_gid' => $data->shopifyShopGid?->__toString(),
                'currency_code' => $data->currencyCode,
                'timezone' => $data->timezone,
                'status' => ShopStatus::Active->value,
                'installed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                $this->refreshExisting($data);
            }
        }, 5);

        $context->response = new ShopResult($data->shopDomain, ShopStatus::Active);

        return $next($context);
    }

    private function refreshExisting(ShopData $data): void
    {
        $shop = Shop::query()->where('shop_domain', $data->shopDomain)->lockForUpdate()->firstOrFail();

        $shop->fill([
            'shopify_shop_gid' => $data->shopifyShopGid?->__toString() ?? $shop->shopify_shop_gid,
            'currency_code' => $data->currencyCode,
            'timezone' => $data->timezone,
        ]);

        if ($shop->status !== ShopStatus::Active) {
            $shop->fill(['status' => ShopStatus::Active, 'installed_at' => now(), 'uninstalled_at' => null]);
        }

        $shop->save();
    }
}
