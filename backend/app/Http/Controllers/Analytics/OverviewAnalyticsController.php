<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Analytics\Services\OverviewAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsDateRangeRequest;
use App\Http\Resources\Analytics\OverviewAnalyticsResource;

final class OverviewAnalyticsController extends Controller
{
    public function __invoke(AnalyticsDateRangeRequest $request, OverviewAnalyticsService $service): OverviewAnalyticsResource
    {
        $shop = $request->resolveShop();

        $data = $service->getOverview($shop, $request->from(), $request->to());

        return new OverviewAnalyticsResource($data);
    }
}
