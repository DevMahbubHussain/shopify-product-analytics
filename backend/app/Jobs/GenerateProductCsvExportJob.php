<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Export;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateProductCsvExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $exportId)
    {
        $this->connection = 'redis';
        $this->queue = 'exports';
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function handle(): void
    {
        $export = Export::query()->find($this->exportId);

        if ($export === null || $export->status === 'COMPLETED') {
            return;
        }

        $export->update(['status' => 'PROCESSING']);

        try {
            $shop = Shop::query()->findOrFail($export->shop_id);

            $tempStream = fopen('php://temp', 'w+');
            if ($tempStream === false) {
                throw new \RuntimeException('Failed to open temporary stream for CSV export.');
            }

            fputcsv($tempStream, [
                'Product Title',
                'Handle',
                'Vendor',
                'Product Type',
                'Net Sales',
                'Gross Sales',
                'Discounts',
                'Refunds',
                'Units Sold',
                'Orders',
            ]);

            $metricsSubquery = DB::table('product_daily_metrics')
                ->where('shop_id', $shop->id)
                ->where('metric_date', '>=', $export->date_from)
                ->where('metric_date', '<=', $export->date_to)
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

            $rows = DB::table('products')
                ->joinSub($metricsSubquery, 'metrics', function ($join): void {
                    $join->on('products.id', '=', 'metrics.product_id');
                })
                ->where('products.shop_id', $shop->id)
                ->whereNull('products.deleted_at')
                ->select([
                    'products.title',
                    'products.handle',
                    'products.vendor',
                    'products.product_type',
                    'metrics.net_sales',
                    'metrics.gross_sales',
                    'metrics.discounts',
                    'metrics.refunds',
                    'metrics.units_sold',
                    'metrics.orders',
                ])
                ->orderByDesc('metrics.net_sales')
                ->orderBy('products.title')
                ->cursor();

            $rowCount = 0;
            foreach ($rows as $row) {
                $rowCount++;
                fputcsv($tempStream, [
                    $row->title,
                    $row->handle,
                    $row->vendor,
                    $row->product_type,
                    sprintf('%.2f', (float) $row->net_sales),
                    sprintf('%.2f', (float) $row->gross_sales),
                    sprintf('%.2f', (float) $row->discounts),
                    sprintf('%.2f', (float) $row->refunds),
                    (int) $row->units_sold,
                    (int) $row->orders,
                ]);
            }

            rewind($tempStream);
            $filePath = "exports/{$shop->id}/{$export->file_name}";
            Storage::disk('local')->put($filePath, $tempStream);
            fclose($tempStream);

            $export->update([
                'status' => 'COMPLETED',
                'file_path' => $filePath,
                'row_count' => $rowCount,
            ]);
        } catch (Throwable $e) {
            $export->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
