<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('services.s2s.secret', 'test-s2s-secret-key-12345');

    // Register a test route guarded by the S2S middleware
    Route::post('/internal/v1/test-handshake', function () {
        return response()->json([
            'status' => 'ok',
            'shop_domain' => request()->attributes->get('shop_domain'),
        ]);
    })->middleware(\App\Http\Middleware\VerifyS2SSignature::class);
});

function generateS2SSignature(string $method, string $path, int $timestamp, string $shopDomain, string $body, string $secret): string
{
    $bodyHash = hash('sha256', $body);
    $payload = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $shopDomain . "\n" . $bodyHash;

    return hash_hmac('sha256', $payload, $secret);
}

it('rejects requests missing the signature header with 401', function () {
    $response = $this->postJson('/internal/v1/test-handshake', ['foo' => 'bar'], [
        'X-App-Shop-Domain' => 'test-shop.myshopify.com',
        'X-App-Timestamp' => (string) time(),
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests missing the shop domain header with 401', function () {
    $timestamp = time();
    $signature = generateS2SSignature('POST', '/internal/v1/test-handshake', $timestamp, '', '{"foo":"bar"}', 'test-s2s-secret-key-12345');

    $response = $this->postJson('/internal/v1/test-handshake', ['foo' => 'bar'], [
        'X-App-Timestamp' => (string) $timestamp,
        'X-App-Signature' => $signature,
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests missing the timestamp header with 401', function () {
    $signature = generateS2SSignature('POST', '/internal/v1/test-handshake', 0, 'test-shop.myshopify.com', '{"foo":"bar"}', 'test-s2s-secret-key-12345');

    $response = $this->postJson('/internal/v1/test-handshake', ['foo' => 'bar'], [
        'X-App-Shop-Domain' => 'test-shop.myshopify.com',
        'X-App-Signature' => $signature,
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests with an expired timestamp (older than 300 seconds) with 401', function () {
    $expiredTimestamp = time() - 305;
    $body = json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR);
    $signature = generateS2SSignature('POST', '/internal/v1/test-handshake', $expiredTimestamp, 'test-shop.myshopify.com', $body, 'test-s2s-secret-key-12345');

    $response = $this->postJson('/internal/v1/test-handshake', ['foo' => 'bar'], [
        'X-App-Shop-Domain' => 'test-shop.myshopify.com',
        'X-App-Timestamp' => (string) $expiredTimestamp,
        'X-App-Signature' => $signature,
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Request timestamp expired or out of allowed window.']);
});

it('rejects requests with a future timestamp drift (> 300 seconds) with 401', function () {
    $futureTimestamp = time() + 305;
    $body = json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR);
    $signature = generateS2SSignature('POST', '/internal/v1/test-handshake', $futureTimestamp, 'test-shop.myshopify.com', $body, 'test-s2s-secret-key-12345');

    $response = $this->postJson('/internal/v1/test-handshake', ['foo' => 'bar'], [
        'X-App-Shop-Domain' => 'test-shop.myshopify.com',
        'X-App-Timestamp' => (string) $futureTimestamp,
        'X-App-Signature' => $signature,
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Request timestamp expired or out of allowed window.']);
});

it('rejects requests with a tampered or invalid signature with 401', function () {
    $timestamp = time();

    $response = $this->postJson('/internal/v1/test-handshake', ['foo' => 'bar'], [
        'X-App-Shop-Domain' => 'test-shop.myshopify.com',
        'X-App-Timestamp' => (string) $timestamp,
        'X-App-Signature' => 'invalid-hex-digest-1234567890abcdef',
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Invalid S2S request signature.']);
});

it('accepts requests with a valid signature and binds the shop domain into request attributes', function () {
    $timestamp = time();
    $shopDomain = 'acme-apparel.myshopify.com';
    $body = json_encode(['batch_id' => 'batch-101'], JSON_THROW_ON_ERROR);
    $signature = generateS2SSignature('POST', '/internal/v1/test-handshake', $timestamp, $shopDomain, $body, 'test-s2s-secret-key-12345');

    $response = $this->postJson('/internal/v1/test-handshake', ['batch_id' => 'batch-101'], [
        'X-App-Shop-Domain' => $shopDomain,
        'X-App-Timestamp' => (string) $timestamp,
        'X-App-Signature' => $signature,
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'shop_domain' => $shopDomain,
        ]);
});

it('successfully completes S2S handshake on the registered /internal/v1/ping endpoint', function () {
    $timestamp = time();
    $shopDomain = 'smart-store-demo.myshopify.com';
    $body = json_encode(['client' => 'react-router-shopify-app'], JSON_THROW_ON_ERROR);
    $signature = generateS2SSignature('POST', '/internal/v1/ping', $timestamp, $shopDomain, $body, (string) config('services.s2s.secret'));

    $response = $this->postJson('/internal/v1/ping', ['client' => 'react-router-shopify-app'], [
        'X-App-Shop-Domain' => $shopDomain,
        'X-App-Timestamp' => (string) $timestamp,
        'X-App-Signature' => $signature,
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'message' => 'S2S handshake verified successfully.',
            'shop_domain' => $shopDomain,
        ]);
});
