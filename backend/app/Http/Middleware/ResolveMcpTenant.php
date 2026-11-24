<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ShopStatus;
use App\Mcp\Support\McpPrincipal;
use App\Models\Shop;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveMcpTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        /**
         * @var McpPrincipal|null $principal
         */
        $principal = $request->attributes->get('mcp_principal');

        if ($principal === null) {
            return new JsonResponse(
                [
                    'error' => 'unauthenticated',
                    'error_description' => 'Unauthenticated MCP principal.',
                ], Response::HTTP_UNAUTHORIZED
            );
        }

        /**
         * @var Shop|null $shop
         */
        $shop = Shop::query()->find($principal->shopId);

        if ($shop === null || $shop->status !== ShopStatus::Active) {
            return new JsonResponse(
                [
                    'error' => 'store_inactive',
                    'error_description' => 'The store is inactive, suspended, or uninstalled.',
                ], Response::HTTP_FORBIDDEN
            );
        }

        $request->attributes->set('mcp_shop', $shop);

        return $next($request);
    }
}
