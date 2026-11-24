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
                DB::raw('COALESCE(ROUND((SUM(refunds) / NULLIF(SUM(gross_sales), 0)) * 100, 2), 0) as refund_rate'),
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
                DB::raw('COALESCE(metrics.refund_rate, 0) as refund_rate'),
            ]);

        if ($request->search() !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($request->search(), 0, 255));
            $query->where('products.title', 'ilike', '%'.$escaped.'%');
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
            'refund_rate' => 'refund_rate',
            'refunds' => 'refunds',
            default => 'net_sales',
        };

        $query->orderBy($sortColumn, $request->sortDirection())
            ->orderBy('products.id', 'desc');

        return $query->paginate(
            perPage: $request->perPageCount(),
            page: $request->pageNumber()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHighRefundProducts(
        Shop $shop,
        string $from,
        string $to,
        string $sortBy = 'refund_rate',
        string $direction = 'desc',
        float|int|string|null $minRefundRatePct = null,
        ?string $minRefundAmount = null,
        int $limit = 20
    ): array {
        $metricsSubquery = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->where('metric_date', '>=', $from)
            ->where('metric_date', '<=', $to)
            ->where('currency_code', $shop->currency_code)
            ->groupBy('product_id')
            ->select([
                'product_id',
                DB::raw('COALESCE(SUM(gross_sales), 0) as gross_sales'),
                DB::raw('COALESCE(SUM(refunds), 0) as refund_amount'),
                DB::raw('COALESCE(SUM(net_sales), 0) as net_sales'),
                DB::raw('COALESCE(SUM(units_sold), 0) as units_sold'),
            ]);

        $query = DB::table('products')
            ->joinSub($metricsSubquery, 'metrics', function ($join): void {
                $join->on('products.id', '=', 'metrics.product_id');
            })
            ->where('products.shop_id', $shop->id)
            ->whereNull('products.deleted_at')
            ->where('metrics.gross_sales', '>', 0)
            ->where('metrics.refund_amount', '>', 0)
            ->select([
                'products.id',
                'products.title',
                'products.handle',
                'products.vendor',
                'products.product_type',
                'products.status',
                DB::raw('ROUND((metrics.refund_amount / metrics.gross_sales) * 100, 2) as refund_rate'),
                'metrics.gross_sales',
                'metrics.refund_amount',
                'metrics.net_sales',
                'metrics.units_sold',
            ]);

        if ($minRefundRatePct !== null) {
            $query->whereRaw('ROUND((metrics.refund_amount / metrics.gross_sales) * 100, 2) >= ?', [(float) $minRefundRatePct]);
        }

        if ($minRefundAmount !== null && $minRefundAmount !== '') {
            $query->where('metrics.refund_amount', '>=', FinancialMath::formatDecimal($minRefundAmount));
        }

        $sortColumn = $sortBy === 'refund_amount' ? 'metrics.refund_amount' : 'refund_rate';
        $dir = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $records = $query->orderBy($sortColumn, $dir)
            ->orderBy('products.id', 'desc')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return $records->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'handle' => (string) $row->handle,
            'vendor' => $row->vendor !== null ? (string) $row->vendor : null,
            'product_type' => $row->product_type !== null ? (string) $row->product_type : null,
            'status' => (string) $row->status,
            'gross_sales' => FinancialMath::formatDecimal((string) $row->gross_sales),
            'refund_amount' => FinancialMath::formatDecimal((string) $row->refund_amount),
            'net_sales' => FinancialMath::formatDecimal((string) $row->net_sales),
            'units_sold' => (int) $row->units_sold,
            'refund_rate' => (string) $row->refund_rate,
        ])->all();
    }
}
