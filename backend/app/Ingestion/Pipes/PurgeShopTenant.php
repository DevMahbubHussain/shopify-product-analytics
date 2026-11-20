<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\ShopRedactData;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\ShopRedactResult;
use App\Services\TenantPurgeService;
use Closure;

final class PurgeShopTenant
{
    public function __construct(private readonly TenantPurgeService $purgeService)
    {
    }

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $data = $context->payload(ShopRedactData::class);

        $this->purgeService->purgeByDomain($data->shopDomain);

        $context->response = new ShopRedactResult($data->shopDomain);

        return $next($context);
    }
}
