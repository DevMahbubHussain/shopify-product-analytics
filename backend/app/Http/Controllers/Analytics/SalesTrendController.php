<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Analytics\Services\SalesTrendAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\SalesTrendRequest;
use App\Http\Resources\Analytics\SalesTrendResource;

final class SalesTrendController extends Controller
{
    public function __invoke(SalesTrendRequest $request, SalesTrendAnalyticsService $service): SalesTrendResource
    {
        $shop = $request->resolveShop();

        $data = $service->getSalesTrend($shop, $request->from(), $request->to(), $request->trendInterval());

        return new SalesTrendResource($data);
    }
}
