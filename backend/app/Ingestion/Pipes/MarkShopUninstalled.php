<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\ShopUninstallData;
use App\Enums\ShopStatus;
use App\Events\TenantDeactivated;
use App\Ingestion\Exceptions\UnknownShop;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\ShopResult;
use App\Models\Shop;
use Closure;
use Illuminate\Support\Facades\DB;

final class MarkShopUninstalled
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $data = $context->payload(ShopUninstallData::class);

        /** @var array{Shop, bool} $outcome */
        $outcome = DB::transaction(function () use ($data): array {
            $shop = Shop::query()->where('shop_domain', $data->shopDomain)->lockForUpdate()->first()
                ?? throw new UnknownShop;

            if ($shop->status === ShopStatus::Uninstalled || $this->predatesInstall($shop, $data)) {
                return [$shop, false];
            }

            $shop->fill(['status' => ShopStatus::Uninstalled, 'uninstalled_at' => $data->uninstalledAt])->save();

            return [$shop, true];
        }, 5);

        [$shop, $deactivated] = $outcome;

        if ($deactivated) {
            event(new TenantDeactivated($shop->id, $data->uninstalledAt));
        }

        $context->response = new ShopResult($data->shopDomain, $shop->status);

        return $next($context);
    }

    /** Shopify may deliver app/uninstalled after the merchant has already reinstalled. */
    private function predatesInstall(Shop $shop, ShopUninstallData $data): bool
    {
        return $shop->installed_at !== null && $data->uninstalledAt->lessThan($shop->installed_at);
    }
}
