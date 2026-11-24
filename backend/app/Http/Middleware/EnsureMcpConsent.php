<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\Support\McpPrincipal;
use App\Models\McpConsent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMcpConsent
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

        $hasConsent = McpConsent::query()
            ->where('shop_id', $principal->shopId)
            ->active()
            ->exists();

        if (! $hasConsent) {
            return new JsonResponse(
                [
                    'error' => 'consent_required',
                    'error_description' => 'Data-sharing authorization has not been granted or has been withdrawn. Authorize data sharing in the AI & MCP section of your store admin.',
                ], Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
