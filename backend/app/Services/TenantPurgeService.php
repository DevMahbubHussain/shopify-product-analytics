<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class TenantPurgeService
{
    public function purgeByDomain(string $shopDomain): void
    {
        $shop = Shop::query()->where('shop_domain', $shopDomain)->first();

        if ($shop === null) {
            return;
        }

        DB::transaction(function () use ($shop): void {
            DB::table('refund_line_items')->where('shop_id', $shop->id)->delete();
            DB::table('order_line_items')->where('shop_id', $shop->id)->delete();
            DB::table('orders')->where('shop_id', $shop->id)->delete();
            DB::table('product_daily_metrics')->where('shop_id', $shop->id)->delete();
            DB::table('product_variants')->where('shop_id', $shop->id)->delete();
            DB::table('products')->where('shop_id', $shop->id)->delete();
            DB::table('sync_runs')->where('shop_id', $shop->id)->delete();
            DB::table('subscriptions')->where('shop_id', $shop->id)->delete();
            DB::table('exports')->where('shop_id', $shop->id)->delete();
            DB::table('webhook_events')->where('shop_id', $shop->id)->delete();
            DB::table('shops')->where('id', $shop->id)->delete();
        }, 5);

        Storage::disk('local')->deleteDirectory("exports/{$shop->id}");
    }
}
