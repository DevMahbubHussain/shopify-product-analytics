<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Requests\Internal\GetSyncStatusRequest;
use App\Http\Resources\SyncRunResource;
use App\Models\SyncRun;
use Illuminate\Http\JsonResponse;

final class SyncStatusController
{
    public function show(GetSyncStatusRequest $request): JsonResponse
    {
        $shop = $request->resolveShop();

        $latestRun = SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('created_at', '>=', $shop->installed_at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return new JsonResponse([
            'sync_run' => $latestRun === null ? null : new SyncRunResource($latestRun),
        ]);
    }
}
