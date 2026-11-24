<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PlanTier;
use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Listeners\QueueMetricAggregationsFromOrders;
use App\Listeners\QueueMetricAggregationsFromRefunds;
use App\Mcp\Support\McpPrincipal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for(
            's2s-ingestion', fn (Request $request): Limit => Limit::perMinute((int) config('services.s2s.ingestion_per_minute'))
                ->by((string) $request->attributes->get('shop_domain'))
        );

        RateLimiter::for(
            'mcp', static function (Request $request): array {
                /** @var McpPrincipal|null $principal */
                $principal = $request->attributes->get('mcp_principal');

                if ($principal === null) {
                    return [Limit::perMinute(60)->by((string) $request->ip())];
                }

                /** @var PlanTier $tier */
                $tier = $request->attributes->get('mcp_plan_tier') ?? PlanTier::Pro;

                return [
                    Limit::perMinute($tier->mcpRateLimitPerMinute())->by('mcp:conn:'.$principal->connectionId),
                    Limit::perHour($tier->mcpRateLimitPerHour())->by('mcp:shop:'.$principal->shopId),
                ];
            }
        );

        Event::listen(OrdersIngested::class, QueueMetricAggregationsFromOrders::class);
        Event::listen(RefundsIngested::class, QueueMetricAggregationsFromRefunds::class);
    }
}
