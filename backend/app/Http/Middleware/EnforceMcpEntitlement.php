<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\PlanEntitlementService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceMcpEntitlement
{
    public function __construct(
        private readonly PlanEntitlementService $entitlementService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Shop|null $shop */
        $shop = $request->attributes->get('mcp_shop');

        if ($shop === null) {
            return new JsonResponse(
                [
                    'error' => 'unauthenticated',
                    'error_description' => 'Unauthenticated MCP principal.',
                ], Response::HTTP_UNAUTHORIZED
            );
        }

        $tier = $this->entitlementService->resolvePlan($shop);

        if (! $tier->canUseMcp()) {
            return new JsonResponse(
                [
                    'error' => 'entitlement_denied',
                    'error_description' => 'The current subscription plan does not allow AI & MCP integrations. Upgrade to Pro to connect AI clients.',
                ], Response::HTTP_FORBIDDEN
            );
        }

        $request->attributes->set('mcp_plan_tier', $tier);

        return $next($request);
    }
}
