<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Internal\Concerns\ResolvesTenantShop;
use App\Http\Requests\Internal\ListMcpAuditLogsRequest;
use App\Http\Resources\Mcp\McpAuditLogResource;
use App\Models\McpAuditLog;
use Illuminate\Http\JsonResponse;

final class McpAuditLogController
{
    use ResolvesTenantShop;

    public function index(ListMcpAuditLogsRequest $request): JsonResponse
    {
        $query = $request->toDto();
        $shop = $this->resolveShop($request);

        $paginator = McpAuditLog::query()
            ->where('shop_id', $shop->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $query->perPage, page: $query->page);

        return new JsonResponse(
            [
                'data' => McpAuditLogResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]
        );
    }
}
