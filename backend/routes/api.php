<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('/api/v1')->group(function () {
    Route::get('/health', function (): JsonResponse {
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

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'database' => $dbOk ? 'connected' : 'disconnected',
            'redis' => $redisOk ? 'connected' : 'disconnected',
            'timestamp' => now()->toISOString(),
        ], $allHealthy ? 200 : 503);
    });
});

/*
|--------------------------------------------------------------------------
| Private Service-to-Service (S2S) Internal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('/internal/v1')->middleware('s2s')->group(function () {
    Route::post('/ping', function (Request $request): JsonResponse {
        return response()->json([
            'status' => 'ok',
            'message' => 'S2S handshake verified successfully.',
            'shop_domain' => $request->attributes->get('shop_domain'),
            'timestamp' => time(),
        ]);
    });
});
