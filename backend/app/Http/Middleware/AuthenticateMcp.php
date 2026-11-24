<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Platform;
use App\Jobs\TouchMcpTokenLastUsed;
use App\Mcp\Support\McpPrincipal;
use App\Mcp\Support\McpTokenService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMcp
{
    private const CACHE_TTL_SECONDS = 60;

    private const LAST_USED_DEBOUNCE_SECONDS = 300; // 5 minutes

    private const MAX_FAILED_ATTEMPTS = 60;

    private const FAILED_DECAY_SECONDS = 60;

    public function __construct(
        private readonly McpTokenService $tokenService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = (string) $request->header('Authorization', '');

        if (! preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $this->unauthorized($request, 'Missing or malformed Authorization header. Expected Bearer token.');
        }

        $plaintextToken = trim($matches[1]);

        if ($plaintextToken === '') {
            return $this->unauthorized($request, 'Empty Bearer token provided.');
        }

        // Fast-fail: Reject tokens without valid prefix before hitting database
        if (! Str::startsWith($plaintextToken, 'spa_mcp_')) {
            return $this->unauthorized($request, 'The access token is invalid, expired, or revoked.');
        }

        $tokenHash = $this->tokenService->hash($plaintextToken);
        $cacheKey = 'mcp:principal:'.$tokenHash;

        /** @var array{shop_id: string, platform: string, staff_ref: string, connection_id: string, connection_type: string, expires_at: string|null}|null $cached */
        $cached = Cache::store('redis')->get($cacheKey);

        if ($cached !== null) {
            $principal = new McpPrincipal(
                shopId: $cached['shop_id'],
                platform: Platform::from($cached['platform']),
                staffRef: $cached['staff_ref'],
                connectionId: $cached['connection_id'],
                connectionType: $cached['connection_type'],
                expiresAt: isset($cached['expires_at']) && $cached['expires_at'] !== null
                    ? CarbonImmutable::parse($cached['expires_at'])
                    : null,
            );

            if ($principal->isExpired()) {
                Cache::store('redis')->forget($cacheKey);

                return $this->unauthorized($request, 'The access token is invalid, expired, or revoked.');
            }
        } else {
            $token = $this->tokenService->findValidToken($plaintextToken);

            if ($token === null) {
                return $this->unauthorized($request, 'The access token is invalid, expired, or revoked.');
            }

            $shop = $token->shop;

            if ($shop === null) {
                return $this->unauthorized($request, 'Associated store tenant could not be resolved.');
            }

            $principal = new McpPrincipal(
                shopId: $token->shop_id,
                platform: $shop->platform,
                staffRef: $token->created_by_staff_ref,
                connectionId: $token->id,
                connectionType: 'token',
                expiresAt: $token->expires_at,
            );

            if ($principal->isExpired()) {
                return $this->unauthorized($request, 'The access token is invalid, expired, or revoked.');
            }

            // Cap cache TTL at remaining seconds until token expiration
            $ttl = self::CACHE_TTL_SECONDS;
            if ($principal->expiresAt !== null) {
                $secondsRemaining = (int) CarbonImmutable::now()->diffInSeconds($principal->expiresAt, false);
                if ($secondsRemaining <= 0) {
                    return $this->unauthorized($request, 'The access token is invalid, expired, or revoked.');
                }
                $ttl = min(self::CACHE_TTL_SECONDS, $secondsRemaining);
            }

            Cache::store('redis')->put(
                $cacheKey,
                [
                    'shop_id' => $principal->shopId,
                    'platform' => $principal->platform->value,
                    'staff_ref' => $principal->staffRef,
                    'connection_id' => $principal->connectionId,
                    'connection_type' => $principal->connectionType,
                    'expires_at' => $principal->expiresAt?->toIso8601String(),
                ],
                $ttl
            );
        }

        $this->recordLastUsedDebounced($principal->connectionId);

        $request->attributes->set('mcp_principal', $principal);
        $request->setUserResolver(static fn (): McpPrincipal => $principal);
        Context::add('mcp_shop_id', $principal->shopId);

        return $next($request);
    }

    private function recordLastUsedDebounced(string $tokenId): void
    {
        $debounceKey = 'mcp:last_used:'.$tokenId;

        if (Cache::store('redis')->add($debounceKey, true, self::LAST_USED_DEBOUNCE_SECONDS)) {
            dispatch(new TouchMcpTokenLastUsed($tokenId, CarbonImmutable::now()));
        }
    }

    private function unauthorized(Request $request, string $message): JsonResponse
    {
        $ip = $request->ip() ?? 'unknown';
        $ipKey = 'mcp:failed_ip:'.$ip;

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_FAILED_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($ipKey);

            return new JsonResponse(
                data: [
                    'error' => 'rate_limited',
                    'error_description' => 'Too many failed authentication attempts from this IP. Please retry in '.$seconds.' seconds.',
                ],
                status: Response::HTTP_TOO_MANY_REQUESTS,
                headers: [
                    'Retry-After' => (string) $seconds,
                    'X-RateLimit-Limit' => (string) self::MAX_FAILED_ATTEMPTS,
                    'X-RateLimit-Remaining' => '0',
                ],
            );
        }

        RateLimiter::hit($ipKey, self::FAILED_DECAY_SECONDS);

        return new JsonResponse(
            data: [
                'error' => 'invalid_token',
                'error_description' => $message,
            ],
            status: Response::HTTP_UNAUTHORIZED,
            headers: [
                'WWW-Authenticate' => sprintf('Bearer error="invalid_token", error_description="%s"', addslashes($message)),
            ],
        );
    }
}
