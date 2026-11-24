<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Internal\Concerns\ResolvesTenantShop;
use App\Http\Requests\Internal\StoreMcpTokenRequest;
use App\Http\Resources\Mcp\GeneratedMcpTokenResource;
use App\Http\Resources\Mcp\McpTokenResource;
use App\Mcp\Support\McpTokenService;
use App\Models\McpConsent;
use App\Models\McpToken;
use App\Services\PlanEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class McpTokenController
{
    use ResolvesTenantShop;

    public function __construct(
        private readonly McpTokenService $tokenService,
        private readonly PlanEntitlementService $planService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);

        $tokens = McpToken::query()
            ->where('shop_id', $shop->id)
            ->latest('created_at')
            ->get();

        return new JsonResponse([
            'tokens' => McpTokenResource::collection($tokens)->resolve(),
        ]);
    }

    public function store(StoreMcpTokenRequest $request): JsonResponse
    {
        $dto = $request->toDto();
        $shop = $this->resolveShop($request);

        $tier = $this->planService->resolvePlan($shop);
        if (! $tier->canUseMcp()) {
            return new JsonResponse([
                'error' => 'entitlement_denied',
                'error_description' => 'The current subscription plan does not allow AI & MCP integrations. Upgrade to Pro to connect AI clients.',
            ], Response::HTTP_FORBIDDEN);
        }

        $hasConsent = McpConsent::query()
            ->where('shop_id', $shop->id)
            ->active()
            ->exists();

        if (! $hasConsent) {
            return new JsonResponse([
                'error' => 'consent_required',
                'error_description' => 'Cannot create tokens without active merchant data-sharing authorization.',
            ], Response::HTTP_FORBIDDEN);
        }

        $generated = $this->tokenService->createToken(
            shop: $shop,
            name: $dto->name,
            staffRef: $dto->staffRef,
            expiresAt: $dto->expiresAt,
        );

        return new JsonResponse([
            'token' => (new GeneratedMcpTokenResource($generated))->resolve(),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return new JsonResponse([
                'error' => 'not_found',
                'error_description' => 'Token not found or already revoked.',
            ], Response::HTTP_NOT_FOUND);
        }

        $shop = $this->resolveShop($request);

        $revoked = $this->tokenService->revokeToken($shop, $id);

        if (! $revoked) {
            return new JsonResponse([
                'error' => 'not_found',
                'error_description' => 'Token not found or already revoked.',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'status' => 'revoked',
            'message' => 'Token revoked successfully.',
        ]);
    }
}
