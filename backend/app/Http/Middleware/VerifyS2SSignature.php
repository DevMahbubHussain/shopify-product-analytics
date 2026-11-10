<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyS2SSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $shopDomain = $request->header('X-App-Shop-Domain');
        $timestamp = $request->header('X-App-Timestamp');
        $signature = $request->header('X-App-Signature');

        if (! $shopDomain || ! $timestamp || ! $signature) {
            return new JsonResponse([
                'error' => 'Missing required S2S authentication headers.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $now = time();
        $requestTime = (int) $timestamp;
        $tolerance = (int) config('services.s2s.tolerance', 300);

        if (abs($now - $requestTime) > $tolerance) {
            return new JsonResponse([
                'error' => 'Request timestamp expired or out of allowed window.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $secret = (string) config('services.s2s.secret', '');
        if ($secret === '') {
            return new JsonResponse([
                'error' => 'Server S2S configuration error.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $method = strtoupper($request->getMethod());
        $path = '/' . ltrim($request->path(), '/');
        $body = $request->getContent();
        $bodyHash = hash('sha256', $body);

        $payload = "{$method}\n{$path}\n{$timestamp}\n{$shopDomain}\n{$bodyHash}";
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return new JsonResponse([
                'error' => 'Invalid S2S request signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('shop_domain', $shopDomain);

        return $next($request);
    }
}
