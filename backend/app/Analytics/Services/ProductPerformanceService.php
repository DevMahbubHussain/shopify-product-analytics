<?php

declare(strict_types=1);

namespace App\Analytics\Services;

use App\Analytics\Domain\FinancialMath;
use App\Http\Requests\Analytics\ProductListRequest;
use App\Models\Shop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ProductPerformanceService
{
    public function getProducts(Shop $shop, ProductListRequest $request): LengthAwarePaginator
    {
        $metricsSubquery = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('metric_date', '>=', $request->from())
            ->where('metric_date', '<=', $request->to())
            ->where('currency_code', $shop->currency_code)
            ->groupBy('product_id')
            ->select([
                'product_id',
                DB::raw('COALESCE(SUM(gross_sales), 0) as gross_sales'),
                DB::raw('COALESCE(SUM(discounts), 0) as discounts'),
                DB::raw('COALESCE(SUM(refunds), 0) as refunds'),
                DB::raw('COALESCE(SUM(net_sales), 0) as net_sales'),
                DB::raw('COALESCE(SUM(units_sold), 0) as units_sold'),
                DB::raw('COALESCE(SUM(order_count), 0) as orders'),
            ]);

        $query = DB::table('products')
            ->leftJoinSub($metricsSubquery, 'metrics', function ($join): void {
                $join->on('products.id', '=', 'metrics.product_id');
            })
            ->where('products.shop_id', $shop->id)
            ->whereNull('products.deleted_at')
            ->select([
                'products.id',
                'products.shopify_product_gid',
                'products.title',
                'products.handle',
                'products.vendor',
                'products.product_type',
                'products.status',
                'products.image_url',
                DB::raw('COALESCE(metrics.gross_sales, 0) as gross_sales'),
                DB::raw('COALESCE(metrics.discounts, 0) as discounts'),
                DB::raw('COALESCE(metrics.refunds, 0) as refunds'),
                DB::raw('COALESCE(metrics.net_sales, 0) as net_sales'),
                DB::raw('COALESCE(metrics.units_sold, 0) as units_sold'),
                DB::raw('COALESCE(metrics.orders, 0) as orders'),
            ]);

        if ($request->search() !== null) {
            $query->where('products.title', 'ilike', '%' . $request->search() . '%');
        }

        if ($request->vendor() !== null) {
            $query->where('products.vendor', '=', $request->vendor());
        }

        if ($request->productType() !== null) {
            $query->where('products.product_type', '=', $request->productType());
        }

        if ($request->status() !== null) {
            $query->where('products.status', '=', $request->status());
        }

        $sortColumn = match ($request->sortField()) {
            'gross_sales' => 'gross_sales',
            'units_sold' => 'units_sold',
            'orders' => 'orders',
            'title' => 'products.title',
            default => 'net_sales',
        };

        $query->orderBy($sortColumn, $request->sortDirection())
            ->orderBy('products.id', 'desc');

        return $query->paginate(
            perPage: $request->perPageCount(),
            page: $request->pageNumber()
        );
    }
}
