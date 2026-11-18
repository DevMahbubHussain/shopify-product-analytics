<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyS2SSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const TEST_S2S_SECRET = 'test-s2s-secret-key-12345';

beforeEach(function (): void {
    config()->set('services.s2s.secret', TEST_S2S_SECRET);

    Route::post('/internal/v1/test-handshake', fn () => response()->json([
        'status' => 'ok',
        'shop_domain' => request()->attributes->get('shop_domain'),
    ]))->middleware(VerifyS2SSignature::class);
});

function generateS2SSignature(
    string $method,
    string $path,
    int $timestamp,
    string $nonce,
    string $shopDomain,
    string $body,
    string $secret = TEST_S2S_SECRET,
): string {
    $payload = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $shopDomain . "\n" . hash('sha256', $body);

    return hash_hmac('sha256', $payload, $secret);
}

/**
 * @param  array<string, string>  $body
 * @param  array<string, string>  $overrides
 */
function signedPost(string $path, array $body, array $overrides = [], ?string $nonce = null, ?int $timestamp = null): TestResponse
{
    $timestamp ??= time();
    $nonce ??= (string) Str::uuid();
    $shopDomain = 'acme-apparel.myshopify.com';
    $json = json_encode($body, JSON_THROW_ON_ERROR);

    $headers = array_merge([
        'X-App-Shop-Domain' => $shopDomain,
        'X-App-Timestamp' => (string) $timestamp,
        'X-App-Nonce' => $nonce,
        'X-App-Signature' => generateS2SSignature('POST', $path, $timestamp, $nonce, $shopDomain, $json),
    ], $overrides);

    return test()->postJson($path, $body, $headers);
}

it('rejects requests missing the signature header with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Signature' => ''])
        ->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests missing the shop domain header with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Shop-Domain' => ''])
        ->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests missing the timestamp header with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Timestamp' => ''])
        ->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests missing the nonce header with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Nonce' => ''])
        ->assertStatus(401)
        ->assertJson(['error' => 'Missing required S2S authentication headers.']);
});

it('rejects requests with a malformed nonce with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], nonce: 'not-a-uuid')
        ->assertStatus(401)
        ->assertJson(['error' => 'Malformed S2S request nonce.']);
});

it('rejects requests with an expired timestamp (older than 300 seconds) with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], timestamp: time() - 305)
        ->assertStatus(401)
        ->assertJson(['error' => 'Request timestamp expired or out of allowed window.']);
});

it('rejects requests with a future timestamp drift (> 300 seconds) with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], timestamp: time() + 305)
        ->assertStatus(401)
        ->assertJson(['error' => 'Request timestamp expired or out of allowed window.']);
});

it('accepts a timestamp exactly at the 300 second boundary', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], timestamp: time() - 300)
        ->assertOk();
});

it('rejects requests with a tampered or invalid signature with 401', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Signature' => 'invalid-hex-digest-1234567890abcdef'])
        ->assertStatus(401)
        ->assertJson(['error' => 'Invalid S2S request signature.']);
});

it('rejects a signature whose nonce was swapped after signing', function (): void {
    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Nonce' => (string) Str::uuid()])
        ->assertStatus(401)
        ->assertJson(['error' => 'Invalid S2S request signature.']);
});

it('rejects a replayed request reusing the same nonce with 401', function (): void {
    $nonce = (string) Str::uuid();
    $timestamp = time();

    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], nonce: $nonce, timestamp: $timestamp)->assertOk();

    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], nonce: $nonce, timestamp: $timestamp)
        ->assertStatus(401)
        ->assertJson(['error' => 'Request nonce already used.']);
});

it('does not consume a nonce when the signature is invalid', function (): void {
    $nonce = (string) Str::uuid();

    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], ['X-App-Signature' => str_repeat('0', 64)], nonce: $nonce)
        ->assertStatus(401);

    signedPost('/internal/v1/test-handshake', ['foo' => 'bar'], nonce: $nonce)->assertOk();
});

it('accepts requests with a valid signature and binds the shop domain into request attributes', function (): void {
    signedPost('/internal/v1/test-handshake', ['batch_id' => 'batch-101'])
        ->assertOk()
        ->assertJson(['status' => 'ok', 'shop_domain' => 'acme-apparel.myshopify.com']);
});

it('successfully completes S2S handshake on the registered /internal/v1/ping endpoint', function (): void {
    signedPost('/internal/v1/ping', ['client' => 'react-router-shopify-app'])
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'message' => 'S2S handshake verified successfully.',
            'shop_domain' => 'acme-apparel.myshopify.com',
        ]);
});
