<?php

declare(strict_types=1);

namespace App\Http\Controllers\Exports;

use App\Enums\ShopStatus;
use App\Http\Requests\Exports\CreateProductExportRequest;
use App\Http\Resources\ExportResource;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Jobs\GenerateProductCsvExportJob;
use App\Models\Export;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductExportController
{
    public function store(CreateProductExportRequest $request): JsonResponse
    {
        $shop = $request->resolveShop();
        $from = $request->from();
        $to = $request->to();

        $export = Export::query()->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shop->id,
            'export_type' => 'products',
            'file_name' => "products_{$from}_{$to}.csv",
            'file_path' => null,
            'row_count' => 0,
            'status' => 'PENDING',
            'date_from' => $from,
            'date_to' => $to,
        ]);

        GenerateProductCsvExportJob::dispatch($export->id);

        return (new ExportResource($export))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $id): ExportResource
    {
        $shop = $this->resolveShop($request);
        $export = Export::query()->where('shop_id', $shop->id)->where('id', $id)->firstOrFail();

        return new ExportResource($export);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $shop = $this->resolveShop($request);
        $export = Export::query()->where('shop_id', $shop->id)->where('id', $id)->firstOrFail();

        if ($export->status !== 'COMPLETED' || $export->file_path === null || ! Storage::disk('local')->exists($export->file_path)) {
            return new JsonResponse([
                'error' => 'export_not_ready',
                'message' => 'Export is not ready for download.',
            ], 409);
        }

        return Storage::disk('local')->download($export->file_path, $export->file_name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function resolveShop(Request $request): Shop
    {
        $shopDomain = (string) $request->attributes->get('shop_domain');
        $shop = Shop::query()->where('shop_domain', $shopDomain)->first() ?? throw new UnknownShop();

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop();
        }

        return $shop;
    }
}
