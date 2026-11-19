<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Listeners\QueueMetricAggregationsFromOrders;
use App\Listeners\QueueMetricAggregationsFromRefunds;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        RateLimiter::for('s2s-ingestion', fn (Request $request): Limit => Limit::perMinute((int) config('services.s2s.ingestion_per_minute'))
            ->by((string) $request->attributes->get('shop_domain')));

        Event::listen(OrdersIngested::class, QueueMetricAggregationsFromOrders::class);
        Event::listen(RefundsIngested::class, QueueMetricAggregationsFromRefunds::class);
    }
}
