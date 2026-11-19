<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Analytics\Services\ProductDetailAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsDateRangeRequest;
use Illuminate\Http\JsonResponse;

final class ProductDetailAnalyticsController extends Controller
{
    public function show(
        AnalyticsDateRangeRequest $request,
        ProductDetailAnalyticsService $service,
        string $id
    ): JsonResponse {
        $shop = $request->resolveShop();

        $data = $service->getProductAnalytics($shop, $id, $request->from(), $request->to());

        return response()->json(['data' => $data]);
    }

    public function variants(
        AnalyticsDateRangeRequest $request,
        ProductDetailAnalyticsService $service,
        string $id
    ): JsonResponse {
        $shop = $request->resolveShop();

        $data = $service->getVariantAnalytics($shop, $id, $request->from(), $request->to());

        return response()->json(['data' => $data]);
    }
}
