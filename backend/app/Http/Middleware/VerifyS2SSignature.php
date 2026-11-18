<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyS2SSignature
{
    private const NONCE_CACHE_PREFIX = 's2s:nonce:';

    public function handle(Request $request, Closure $next): Response
    {
        $shopDomain = (string) $request->header('X-App-Shop-Domain', '');
        $timestamp = (string) $request->header('X-App-Timestamp', '');
        $nonce = (string) $request->header('X-App-Nonce', '');
        $signature = (string) $request->header('X-App-Signature', '');

        if ($shopDomain === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->unauthorized('Missing required S2S authentication headers.');
        }

        if (! Str::isUuid($nonce)) {
            return $this->unauthorized('Malformed S2S request nonce.');
        }

        if (! $this->isWithinTolerance((int) $timestamp)) {
            return $this->unauthorized('Request timestamp expired or out of allowed window.');
        }

        $secret = (string) config('services.s2s.secret', '');
        if ($secret === '') {
            return new JsonResponse(['error' => 'Server S2S configuration error.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $expectedSignature = hash_hmac('sha256', $this->canonicalPayload($request, $timestamp, $nonce, $shopDomain), $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return $this->unauthorized('Invalid S2S request signature.');
        }

        if (! $this->claimNonce($nonce)) {
            return $this->unauthorized('Request nonce already used.');
        }

        $request->attributes->set('shop_domain', $shopDomain);

        return $next($request);
    }

    private function tolerance(): int
    {
        return (int) config('services.s2s.tolerance', 300);
    }

    private function isWithinTolerance(int $timestamp): bool
    {
        return abs(time() - $timestamp) <= $this->tolerance();
    }

    private function canonicalPayload(Request $request, string $timestamp, string $nonce, string $shopDomain): string
    {
        return implode("\n", [
            strtoupper($request->getMethod()),
            '/' . ltrim($request->path(), '/'),
            $timestamp,
            $nonce,
            $shopDomain,
            hash('sha256', $request->getContent()),
        ]);
    }

    /** Nonce TTL spans both sides of the timestamp window so a replay can never outlive its nonce record. */
    private function claimNonce(string $nonce): bool
    {
        return Cache::store('redis')->add(self::NONCE_CACHE_PREFIX . $nonce, 1, $this->tolerance() * 2);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
