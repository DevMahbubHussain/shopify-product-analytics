<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\EnforceMcpEntitlement;
use App\Http\Middleware\EnsureMcpConsent;
use App\Http\Middleware\ResolveMcpTenant;
use App\Jobs\TouchMcpTokenLastUsed;
use App\Mcp\Support\McpPrincipal;
use App\Mcp\Support\McpTokenService;
use App\Models\McpConsent;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->shop = Shop::create([
        'shop_domain' => 'middleware-test.myshopify.com',
        'shopify_shop_gid' => 'gid://shopify/Shop/99001',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
    ]);

    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shop->id,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/99001',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->tokenService = app(McpTokenService::class);

    // Register a test route protected by the full MCP middleware pipeline
    Route::middleware([
        AuthenticateMcp::class,
        ResolveMcpTenant::class,
        EnsureMcpConsent::class,
        EnforceMcpEntitlement::class,
    ])->post('/_test/mcp/pipeline', function (Request $request) {
        /** @var McpPrincipal $principal */
        $principal = $request->attributes->get('mcp_principal');
        /** @var Shop $shop */
        $shop = $request->attributes->get('mcp_shop');

        return response()->json([
            'status' => 'ok',
            'shop_id' => $principal->shopId,
            'shop_domain' => $shop->shop_domain,
            'platform' => $principal->platform->value,
            'plan_tier' => $request->attributes->get('mcp_plan_tier')->value,
        ]);
    });
});

describe('MCP Middleware Pipeline', function (): void {
    it('rejects requests without Authorization header with 401 and WWW-Authenticate metadata', function (): void {
        $response = $this->postJson('/_test/mcp/pipeline');

        $response->assertStatus(401)
            ->assertHeader('WWW-Authenticate')
            ->assertJson([
                'error' => 'invalid_token',
            ]);
    });

    it('rejects requests with malformed Authorization header with 401', function (): void {
        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'invalid_token',
            ]);
    });

    it('rejects tokens without spa_mcp_ prefix before querying the database', function (): void {
        DB::enableQueryLog();

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer invalid_prefix_random_token_123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'invalid_token',
            ]);

        // Verify zero queries touched mcp_tokens
        $mcpQueries = array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains((string) $q['query'], 'mcp_tokens')
        );
        expect($mcpQueries)->toBeEmpty();
    });

    it('accepts lowercase bearer authorization scheme per RFC 6750', function (): void {
        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Lowercase Bearer Client',
            staffRef: 'staff_1',
        );

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'bearer '.$generated->plaintextToken,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
            ]);
    });

    it('rejects non-existent tokens with 401', function (): void {
        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer spa_mcp_invalidtoken12345678901234567890123456789012345',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'invalid_token',
                'error_description' => 'The access token is invalid, expired, or revoked.',
            ]);
    });

    it('rejects revoked tokens with 401 and immediately evicts cached token', function (): void {
        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Revoked Token',
            staffRef: 'staff_1',
        );

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        // Request 1: Successfully authenticates and primes cache
        $first = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);
        $first->assertStatus(200);

        // Verify token is cached
        $cacheKey = 'mcp:principal:'.$this->tokenService->hash($generated->plaintextToken);
        expect(Cache::store('redis')->get($cacheKey))->not->toBeNull();

        // Revoke token via service
        $this->tokenService->revokeToken($this->shop, $generated->token->id);

        // Verify cache was immediately cleared
        expect(Cache::store('redis')->get($cacheKey))->toBeNull();

        // Request 2: Must immediately return 401 on the very next request
        $second = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);
        $second->assertStatus(401)
            ->assertJson([
                'error' => 'invalid_token',
            ]);
    });

    it('rejects expired tokens and caps cache TTL to remaining lifetime', function (): void {
        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Expiring Token',
            staffRef: 'staff_1',
            expiresAt: CarbonImmutable::now()->addSeconds(10),
        );

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        $res = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);
        $res->assertStatus(200);

        // Fast forward 15 seconds so token is expired
        $this->travel(15)->seconds();

        $expiredRes = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);
        $expiredRes->assertStatus(401)
            ->assertJson([
                'error' => 'invalid_token',
            ]);
    });

    it('rejects Free tier stores with 403 entitlement_denied', function (): void {
        // Remove Pro subscription
        DB::table('subscriptions')->where('shop_id', $this->shop->id)->delete();

        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Free Tier Client',
            staffRef: 'staff_1',
        );

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'entitlement_denied',
            ]);
    });

    it('rejects uninstalled or inactive store tenants with 403 store_inactive', function (): void {
        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Token for Uninstalled Shop',
            staffRef: 'staff_1',
        );

        $this->shop->update(['status' => 'uninstalled']);

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'store_inactive',
            ]);
    });

    it('rejects requests without merchant data-sharing consent with 403 consent_required', function (): void {
        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Token without Consent',
            staffRef: 'staff_1',
        );

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'consent_required',
            ]);
    });

    it('rejects requests when consent was withdrawn with 403 consent_required', function (): void {
        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Token with Withdrawn Consent',
            staffRef: 'staff_1',
        );

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now()->subDay(),
            'withdrawn_at' => now(),
        ]);

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'consent_required',
            ]);
    });

    it('dispatches TouchMcpTokenLastUsed queued job on successful authentication', function (): void {
        Queue::fake([TouchMcpTokenLastUsed::class]);

        $generated = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Last Used Client',
            staffRef: 'staff_1',
        );

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        $response = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer '.$generated->plaintextToken,
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(TouchMcpTokenLastUsed::class, function (TouchMcpTokenLastUsed $job) use ($generated): bool {
            return $job->tokenId === $generated->token->id;
        });
    });

    it('enforces unauthenticated IP rate limiting returning 429 after 60 failed attempts', function (): void {
        RateLimiter::clear('mcp:failed_ip:127.0.0.1');

        for ($i = 0; $i < 60; $i++) {
            $res = $this->postJson('/_test/mcp/pipeline', [], [
                'Authorization' => 'Bearer spa_mcp_invalid'.$i,
            ]);
            $res->assertStatus(401);
        }

        // 61st attempt gets 429
        $throttled = $this->postJson('/_test/mcp/pipeline', [], [
            'Authorization' => 'Bearer spa_mcp_invalid61',
        ]);

        $throttled->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson([
                'error' => 'rate_limited',
            ]);
    });

    it('does not throttle valid client requests on shared provider IPs when failed login limit is untouched', function (): void {
        RateLimiter::clear('mcp:failed_ip:127.0.0.1');

        // Create Shop B on Pro tier
        $shopB = Shop::create([
            'shop_domain' => 'shop-b.myshopify.com',
            'shopify_shop_gid' => 'gid://shopify/Shop/99002',
            'currency_code' => 'USD',
            'timezone' => 'America/Chicago',
            'status' => 'active',
            'installed_at' => now(),
        ]);

        DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => $shopB->id,
            'shopify_subscription_gid' => 'gid://shopify/AppSubscription/99002',
            'plan_key' => 'pro',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        McpConsent::create([
            'shop_id' => $this->shop->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        McpConsent::create([
            'shop_id' => $shopB->id,
            'platform' => Platform::Shopify,
            'granted_by_staff_ref' => 'staff_2',
            'policy_version' => 'v1.0',
            'granted_at' => now(),
        ]);

        $tokenA = $this->tokenService->createToken(shop: $this->shop, name: 'Client A', staffRef: 'staff_1');
        $tokenB = $this->tokenService->createToken(shop: $shopB, name: 'Client B', staffRef: 'staff_2');

        // Both tokens send 40 requests each from the SAME IP (80 total)
        for ($i = 0; $i < 40; $i++) {
            $this->postJson('/_test/mcp/pipeline', [], [
                'Authorization' => 'Bearer '.$tokenA->plaintextToken,
            ])->assertStatus(200);

            $this->postJson('/_test/mcp/pipeline', [], [
                'Authorization' => 'Bearer '.$tokenB->plaintextToken,
            ])->assertStatus(200);
        }

        // All 80 requests succeed with 200 without getting throttled by IP
    });
});
