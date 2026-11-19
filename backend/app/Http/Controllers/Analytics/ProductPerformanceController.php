<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Analytics\Services\ProductPerformanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ProductListRequest;
use App\Http\Resources\Analytics\ProductPerformanceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductPerformanceController extends Controller
{
    public function __invoke(ProductListRequest $request, ProductPerformanceService $service): AnonymousResourceCollection
    {
        $shop = $request->resolveShop();

        $paginator = $service->getProducts($shop, $request);

        return ProductPerformanceResource::collection($paginator);
    }
}
