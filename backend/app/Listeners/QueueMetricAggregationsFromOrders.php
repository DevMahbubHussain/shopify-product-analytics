<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Analytics\Services\StoreTimezoneService;
use App\Events\OrdersIngested;
use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class QueueMetricAggregationsFromOrders implements ShouldQueue
{
    use InteractsWithQueue;

    public string $connection = 'redis';

    public string $queue = 'default';

    public function __construct(
        private readonly StoreTimezoneService $timezoneService
    ) {}

    public function handle(OrdersIngested $event): void
    {
        if (empty($event->orderIds) && empty($event->affectedBuckets)) {
            return;
        }

        if (! empty($event->affectedBuckets)) {
            $dispatched = [];
            foreach ($event->affectedBuckets as $bucket) {
                $productId = (string) $bucket['product_id'];
                $metricDate = (string) $bucket['metric_date'];
                $key = "{$productId}:{$metricDate}";

                if (isset($dispatched[$key])) {
                    continue;
                }

                $dispatched[$key] = true;
                AggregateProductDailyMetricsJob::dispatch($event->shopId, $productId, $metricDate);
            }

            return;
        }

        $shop = DB::table('shops')->where('id', $event->shopId)->first(['id', 'timezone']);
        if ($shop === null) {
            return;
        }

        $lines = DB::table('order_line_items')
            ->join('orders', function ($join): void {
                $join->on('orders.id', '=', 'order_line_items.order_id')
                    ->on('orders.shop_id', '=', 'order_line_items.shop_id');
            })
            ->where('order_line_items.shop_id', $event->shopId)
            ->whereIn('order_line_items.order_id', $event->orderIds)
            ->whereNotNull('order_line_items.product_id')
            ->whereNotNull('orders.processed_at')
            ->select([
                'order_line_items.product_id',
                'orders.processed_at',
            ])
            ->get();

        $dispatched = [];

        foreach ($lines as $line) {
            $productId = (string) $line->product_id;
            $metricDate = $this->timezoneService->toStoreDate($line->processed_at, $shop->timezone);
            $key = "{$productId}:{$metricDate}";

            if (isset($dispatched[$key])) {
                continue;
            }

            $dispatched[$key] = true;
            AggregateProductDailyMetricsJob::dispatch($event->shopId, $productId, $metricDate);
        }
    }
}
