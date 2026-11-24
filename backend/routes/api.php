<?php

declare(strict_types=1);

use App\Http\Controllers\Analytics\OverviewAnalyticsController;
use App\Http\Controllers\Analytics\ProductDetailAnalyticsController;
use App\Http\Controllers\Analytics\ProductPerformanceController;
use App\Http\Controllers\Analytics\SalesTrendController;
use App\Http\Controllers\Exports\ProductExportController;
use App\Http\Controllers\Internal\IngestionController;
use App\Http\Controllers\Internal\McpConsentController;
use App\Http\Controllers\Internal\McpTokenController;
use App\Http\Controllers\Internal\SubscriptionStatusController;
use App\Http\Controllers\Internal\SyncStatusController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public & S2S API Routes
|--------------------------------------------------------------------------
*/
$healthCheckHandler = function (): JsonResponse {
    $dbOk = true;
    $redisOk = true;

    try {
        DB::connection()->getPdo();
    } catch (\Throwable) {
        $dbOk = false;
    }

    try {
        Redis::connection()->ping();
    } catch (\Throwable) {
        $redisOk = false;
    }

    $allHealthy = $dbOk && $redisOk;

    return response()->json(
        [
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'database' => $dbOk ? 'connected' : 'disconnected',
            'redis' => $redisOk ? 'connected' : 'disconnected',
            'timestamp' => now()->toISOString(),
        ],
        $allHealthy ? 200 : 503
    );
};

Route::get('/healthz', $healthCheckHandler);

Route::prefix('/api/v1')->group(
    function () use ($healthCheckHandler) {
        Route::get('/health', $healthCheckHandler);

        Route::middleware('s2s')->group(
            function (): void {
                Route::get('products', ProductPerformanceController::class);
                Route::get('products/{id}/analytics', [ProductDetailAnalyticsController::class, 'show']);
                Route::get('products/{id}/variants/analytics', [ProductDetailAnalyticsController::class, 'variants']);

                Route::prefix('analytics')->group(
                    function (): void {
                        Route::get('overview', OverviewAnalyticsController::class);
                        Route::get('sales-trend', SalesTrendController::class);
                    }
                );

                Route::prefix('exports')->controller(ProductExportController::class)->group(
                    function (): void {
                        Route::post('products', 'store');
                        Route::get('{id}', 'show');
                        Route::get('{id}/download', 'download');
                    }
                );
            }
        );
    }
);

/*
|--------------------------------------------------------------------------
| Private Service-to-Service (S2S) Internal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('/internal/v1')->middleware('s2s')->group(
    function () {
        Route::post(
            '/ping', function (Request $request): JsonResponse {
                return response()->json(
                    [
                    'status' => 'ok',
                    'message' => 'S2S handshake verified successfully.',
                    'shop_domain' => $request->attributes->get('shop_domain'),
                    'timestamp' => time(),
                    ]
                );
            }
        );

        Route::get('subscription', [SubscriptionStatusController::class, 'show']);

        Route::prefix('sync')->controller(SyncStatusController::class)->group(
            function (): void {
                Route::get('status', 'show');
            }
        );

        Route::prefix('mcp')->group(
            function (): void {
                Route::get('status', [McpConsentController::class, 'status']);
                Route::post('consent', [McpConsentController::class, 'grant']);
                Route::delete('consent', [McpConsentController::class, 'withdraw']);

                Route::get('connections', [McpTokenController::class, 'index']);
                Route::post('tokens', [McpTokenController::class, 'store']);
                Route::delete('connections/{id}', [McpTokenController::class, 'destroy'])->whereUuid('id');
            }
        );

        Route::prefix('ingestion')
            ->middleware('throttle:s2s-ingestion')
            ->controller(IngestionController::class)
            ->group(
                function (): void {
                    Route::post('shop', 'shop');
                    Route::post('shop-uninstall', 'shopUninstall');
                    Route::post('products', 'products');
                    Route::post('products-delete', 'productsDelete');
                    Route::post('orders', 'orders');
                    Route::post('refunds', 'refunds');
                    Route::post('webhook-events', 'webhookEvents');
                    Route::post('sync-status', 'syncStatus');
                    Route::post('subscription', 'subscription');
                    Route::post('shop-redact', 'shopRedact');
                }
            );
    }
);
