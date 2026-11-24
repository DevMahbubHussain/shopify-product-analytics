<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Internal\Concerns\ResolvesTenantShop;
use App\Http\Requests\Internal\McpConsentRequest;
use App\Http\Resources\Mcp\McpConsentResource;
use App\Models\McpConsent;
use App\Models\McpToken;
use App\Models\Shop;
use App\Services\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class McpConsentController
{
    use ResolvesTenantShop;

    public function __construct(
        private readonly PlanEntitlementService $planService,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);

        /** @var McpConsent|null $consent */
        $consent = McpConsent::query()
            ->where('shop_id', $shop->id)
            ->active()
            ->latest('granted_at')
            ->first();

        $tier = $this->planService->resolvePlan($shop);
        $activeTokensCount = McpToken::query()
            ->where('shop_id', $shop->id)
            ->active()
            ->count();

        return new JsonResponse(
            [
                'has_consent' => $consent !== null,
                'consent' => $consent !== null ? (new McpConsentResource($consent))->resolve() : null,
                'plan' => [
                    'tier' => $tier->value,
                    'can_use_mcp' => $tier->canUseMcp(),
                    'history_days' => $tier->maxAnalyticsDays(),
                    'rate_limit_per_minute' => $tier->mcpRateLimitPerMinute(),
                ],
                'counts' => [
                    'active_tokens' => $activeTokensCount,
                    'active_oauth_grants' => 0,
                ],
            ]
        );
    }

    public function grant(McpConsentRequest $request): JsonResponse
    {
        $dto = $request->toDto();
        $shop = $this->resolveShop($request);

        /** @var McpConsent $consent */
        $consent = DB::transaction(
            static function () use ($shop, $dto): McpConsent {
                // Acquire deterministic row lock on parent shop to serialize concurrent grants
                Shop::query()->where('id', $shop->id)->lockForUpdate()->first();

                /** @var McpConsent|null $existing */
                $existing = McpConsent::query()
                    ->where('shop_id', $shop->id)
                    ->active()
                    ->first();

                if ($existing !== null) {
                    if ($existing->policy_version === $dto->policyVersion && $existing->granted_by_staff_ref === $dto->staffRef) {
                        return $existing;
                    }
                    $existing->update(['withdrawn_at' => CarbonImmutable::now()]);
                }

                return McpConsent::create(
                    [
                        'shop_id' => $shop->id,
                        'platform' => $shop->platform,
                        'granted_by_staff_ref' => $dto->staffRef,
                        'policy_version' => $dto->policyVersion,
                        'granted_at' => CarbonImmutable::now(),
                    ]
                );
            }, 5
        );

        return new JsonResponse(
            [
                'status' => 'granted',
                'consent' => (new McpConsentResource($consent))->resolve(),
            ],
            Response::HTTP_CREATED
        );
    }

    public function withdraw(Request $request): JsonResponse
    {
        $shop = $this->resolveShop($request);

        /** @var list<string> $tokenHashes */
        $tokenHashes = DB::transaction(
            static function () use ($shop): array {
                $now = CarbonImmutable::now();

                $hashes = McpToken::query()
                    ->where('shop_id', $shop->id)
                    ->whereNull('revoked_at')
                    ->pluck('token_hash')
                    ->all();

                McpConsent::query()
                    ->where('shop_id', $shop->id)
                    ->active()
                    ->update(['withdrawn_at' => $now]);

                McpToken::query()
                    ->where('shop_id', $shop->id)
                    ->active()
                    ->update(['revoked_at' => $now]);

                return $hashes;
            }, 5
        );

        foreach ($tokenHashes as $hash) {
            Cache::store('redis')->forget('mcp:principal:'.$hash);
        }

        return new JsonResponse(
            [
                'status' => 'withdrawn',
                'message' => 'Consent withdrawn and all active connections revoked.',
            ]
        );
    }
}
