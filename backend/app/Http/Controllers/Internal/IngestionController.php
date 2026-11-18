<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Requests\Internal\DeleteProductsRequest;
use App\Http\Requests\Internal\IngestOrdersRequest;
use App\Http\Requests\Internal\IngestProductsRequest;
use App\Http\Requests\Internal\IngestRefundsRequest;
use App\Http\Requests\Internal\IngestShopRequest;
use App\Http\Requests\Internal\IngestionRequest;
use App\Http\Requests\Internal\RecordWebhookDeliveryRequest;
use App\Http\Requests\Internal\ReportSyncStatusRequest;
use App\Http\Requests\Internal\UninstallShopRequest;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Pipes\DeduplicateWebhookDelivery;
use App\Ingestion\Pipes\DispatchIngestedEvents;
use App\Ingestion\Pipes\MarkShopUninstalled;
use App\Ingestion\Pipes\RecordSyncStatus;
use App\Ingestion\Pipes\ResolveShopTenant;
use App\Ingestion\Pipes\SoftDeleteProducts;
use App\Ingestion\Pipes\UpsertOrderBatch;
use App\Ingestion\Pipes\UpsertProductBatch;
use App\Ingestion\Pipes\UpsertRefundBatch;
use App\Ingestion\Pipes\UpsertShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

final class IngestionController
{
    public function shop(IngestShopRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [UpsertShop::class]);
    }

    public function shopUninstall(UninstallShopRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [MarkShopUninstalled::class]);
    }

    public function products(IngestProductsRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [ResolveShopTenant::class, UpsertProductBatch::class, DispatchIngestedEvents::class]);
    }

    public function productsDelete(DeleteProductsRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [ResolveShopTenant::class, SoftDeleteProducts::class]);
    }

    public function orders(IngestOrdersRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [ResolveShopTenant::class, UpsertOrderBatch::class, DispatchIngestedEvents::class]);
    }

    public function refunds(IngestRefundsRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [ResolveShopTenant::class, UpsertRefundBatch::class, DispatchIngestedEvents::class]);
    }

    public function webhookEvents(RecordWebhookDeliveryRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [ResolveShopTenant::class, DeduplicateWebhookDelivery::class]);
    }

    public function syncStatus(ReportSyncStatusRequest $request): JsonResponse
    {
        return $this->run($request, $request->toDto(), [ResolveShopTenant::class, RecordSyncStatus::class]);
    }

    /** @param list<class-string> $pipes */
    private function run(IngestionRequest $request, object $payload, array $pipes): JsonResponse
    {
        $response = resolve(Pipeline::class)
            ->send(new IngestionContext($request->shopDomain(), $payload))
            ->through($pipes)
            ->then(fn (IngestionContext $context): IngestionResponse => $context->response());

        return new JsonResponse($response, $response->status());
    }
}
