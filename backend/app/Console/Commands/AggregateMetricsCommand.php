<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Services\StoreTimezoneService;
use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AggregateMetricsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'analytics:aggregate
                            {--shop= : Specific shop domain to aggregate metrics for}
                            {--now : Run aggregation synchronously instead of pushing to queue}';

    /**
     * @var string
     */
    protected $description = 'Discovers orders and line items to calculate and aggregate daily product metrics';

    public function handle(StoreTimezoneService $timezoneService): int
    {
        $shopDomain = $this->option('shop');
        $runNow = (bool) $this->option('now');

        $query = DB::table('shops')->where('status', 'active');
        if ($shopDomain !== null && $shopDomain !== '') {
            $query->where('shop_domain', $shopDomain);
        }

        $shops = $query->get(['id', 'shop_domain', 'timezone']);

        if ($shops->isEmpty()) {
            if ($shopDomain !== null && $shopDomain !== '') {
                $this->error("Shop not found: [{$shopDomain}].");

                return self::FAILURE;
            }

            $this->info('No active shops found to aggregate.');

            return self::SUCCESS;
        }

        $totalDispatched = 0;

        foreach ($shops as $shop) {
            $this->info("Scanning orders for shop: {$shop->shop_domain}");

            $lines = DB::table('order_line_items')
                ->join('orders', function ($join): void {
                    $join->on('orders.id', '=', 'order_line_items.order_id')
                        ->on('orders.shop_id', '=', 'order_line_items.shop_id');
                })
                ->where('order_line_items.shop_id', $shop->id)
                ->whereNotNull('order_line_items.product_id')
                ->whereNotNull('orders.processed_at')
                ->select([
                    'order_line_items.product_id',
                    'orders.processed_at',
                ])
                ->cursor();

            $dispatched = [];

            foreach ($lines as $line) {
                $productId = (string) $line->product_id;
                $metricDate = $timezoneService->toStoreDate($line->processed_at, $shop->timezone);
                $key = "{$productId}:{$metricDate}";

                if (isset($dispatched[$key])) {
                    continue;
                }

                $dispatched[$key] = true;
                $totalDispatched++;

                if ($runNow) {
                    (new AggregateProductDailyMetricsJob($shop->id, $productId, $metricDate))->handle($timezoneService);
                } else {
                    AggregateProductDailyMetricsJob::dispatch($shop->id, $productId, $metricDate);
                }
            }

            $this->info('Found ' . count($dispatched) . " distinct (product, date) aggregations for {$shop->shop_domain}.");
        }

        $this->info('Completed metrics aggregation' . ($runNow ? ' synchronously.' : ' dispatch to queue. Total: ' . $totalDispatched));

        return self::SUCCESS;
    }
}
