<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

require_once __DIR__.'/Support/ingestion.php';

const INGESTION_TEST_SECRET = 'ingestion-test-secret';

/** @param array<string, mixed> $body */
function s2sPost(string $path, array $body, string $shopDomain = 'acme.myshopify.com'): TestResponse
{
    config()->set('services.s2s.secret', INGESTION_TEST_SECRET);

    $timestamp = (string) time();
    $nonce = (string) Str::uuid();
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, $shopDomain, hash('sha256', $json)]);

    return test()->call('POST', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_APP_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_APP_TIMESTAMP' => $timestamp,
        'HTTP_X_APP_NONCE' => $nonce,
        'HTTP_X_APP_SIGNATURE' => hash_hmac('sha256', $canonical, INGESTION_TEST_SECRET),
    ], $json);
}

if (! function_exists('s2sGet')) {
    function s2sGet(string $path, string $shopDomain = 'acme.myshopify.com'): TestResponse
    {
        config()->set('services.s2s.secret', INGESTION_TEST_SECRET);

        $parsedPath = parse_url($path, PHP_URL_PATH) ?? $path;
        $normalizedPath = '/'.ltrim($parsedPath, '/');
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $canonical = implode("\n", ['GET', $normalizedPath, $timestamp, $nonce, $shopDomain, hash('sha256', '')]);

        return test()->call('GET', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_APP_SHOP_DOMAIN' => $shopDomain,
            'HTTP_X_APP_TIMESTAMP' => $timestamp,
            'HTTP_X_APP_NONCE' => $nonce,
            'HTTP_X_APP_SIGNATURE' => hash_hmac('sha256', $canonical, INGESTION_TEST_SECRET),
        ]);
    }
}
