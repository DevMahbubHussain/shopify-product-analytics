<?php

declare(strict_types=1);

use App\Models\McpConsent;
use App\Models\McpToken;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function s2sDelete(string $path, string $shopDomain = 'acme.myshopify.com'): TestResponse
{
    config()->set('services.s2s.secret', INGESTION_TEST_SECRET);

    $parsedPath = parse_url($path, PHP_URL_PATH) ?? $path;
    $normalizedPath = '/'.ltrim($parsedPath, '/');
    $timestamp = (string) time();
    $nonce = (string) Str::uuid();
    $canonical = implode("\n", ['DELETE', $normalizedPath, $timestamp, $nonce, $shopDomain, hash('sha256', '')]);

    return test()->call('DELETE', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_APP_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_APP_TIMESTAMP' => $timestamp,
        'HTTP_X_APP_NONCE' => $nonce,
        'HTTP_X_APP_SIGNATURE' => hash_hmac('sha256', $canonical, INGESTION_TEST_SECRET),
    ]);
}

function enableProPlan(string $shopId): void
{
    DB::table('subscriptions')->updateOrInsert(
        ['shop_id' => $shopId],
        [
            'id' => (string) Str::uuid(),
            'shopify_subscription_gid' => 'gid://shopify/AppSubscription/'.random_int(10000, 99999),
            'plan_key' => 'pro',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
}

beforeEach(function (): void {
    $this->shopId = installShop('acme.myshopify.com');
});

describe('Internal S2S MCP Management API', function (): void {
    it('returns default status when no consent has been granted and reflects Free tier restrictions', function (): void {
        s2sGet('/internal/v1/mcp/status')
            ->assertOk()
            ->assertJson([
                'has_consent' => false,
                'consent' => null,
                'plan' => [
                    'tier' => 'free',
                    'can_use_mcp' => false,
                    'history_days' => 30,
                    'rate_limit_per_minute' => 0,
                ],
                'counts' => [
                    'active_tokens' => 0,
                    'active_oauth_grants' => 0,
                ],
            ]);
    });

    it('blocks token creation on Free tier with 403 entitlement_denied', function (): void {
        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'gid://shopify/Staff/101',
            'policy_version' => 'v1.0',
        ])->assertCreated();

        s2sPost('/internal/v1/mcp/tokens', [
            'name' => 'Free Attempt',
            'staff_ref' => 'gid://shopify/Staff/101',
        ])->assertStatus(403)
            ->assertJson([
                'error' => 'entitlement_denied',
            ]);
    });

    it('grants merchant data-sharing consent and is idempotent', function (): void {
        enableProPlan($this->shopId);

        $response1 = s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'gid://shopify/Staff/101',
            'policy_version' => 'v1.0',
        ]);

        $response1->assertCreated()
            ->assertJson([
                'status' => 'granted',
                'consent' => [
                    'platform' => 'shopify',
                    'granted_by_staff_ref' => 'gid://shopify/Staff/101',
                    'policy_version' => 'v1.0',
                ],
            ]);

        $consentId = $response1->json('consent.id');

        // Granting again with same staff & version returns existing active consent without creating extra rows
        $response2 = s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'gid://shopify/Staff/101',
            'policy_version' => 'v1.0',
        ]);

        $response2->assertCreated()
            ->assertJsonPath('consent.id', $consentId);

        // Assert strictly exactly 1 active consent in database (satisfies partial unique index)
        expect(McpConsent::where('shop_id', $this->shopId)->active()->count())->toBe(1);
    });

    it('safely handles concurrent consent grants without throwing 500 or violating unique index', function (): void {
        enableProPlan($this->shopId);

        // Simulate concurrent attempts with different staff references
        $res1 = s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'gid://shopify/Staff/201',
            'policy_version' => 'v1.0',
        ]);
        $res2 = s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'gid://shopify/Staff/202',
            'policy_version' => 'v1.0',
        ]);

        $res1->assertCreated();
        $res2->assertCreated();

        // Exactly one active consent survives and no 500 was thrown
        expect(McpConsent::where('shop_id', $this->shopId)->active()->count())->toBe(1);
    });

    it('blocks token creation when merchant data-sharing consent has not been granted', function (): void {
        enableProPlan($this->shopId);

        s2sPost('/internal/v1/mcp/tokens', [
            'name' => 'Attempted Agent',
            'staff_ref' => 'gid://shopify/Staff/102',
        ])->assertStatus(403)
            ->assertJson([
                'error' => 'consent_required',
            ]);
    });

    it('creates a static token returning plaintext once and storing hash only', function (): void {
        enableProPlan($this->shopId);

        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'gid://shopify/Staff/103',
            'policy_version' => 'v1.0',
        ])->assertCreated();

        $response = s2sPost('/internal/v1/mcp/tokens', [
            'name' => 'Cursor Editor',
            'staff_ref' => 'gid://shopify/Staff/103',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token' => [
                    'id',
                    'name',
                    'token_prefix',
                    'plaintext_token',
                    'created_by_staff_ref',
                    'created_at',
                ],
            ]);

        $plaintext = $response->json('token.plaintext_token');
        expect($plaintext)->toStartWith('spa_mcp_');

        // Confirm database stores hash, never plaintext
        $this->assertDatabaseMissing('mcp_tokens', [
            'token_hash' => $plaintext,
        ]);
        $this->assertDatabaseHas('mcp_tokens', [
            'name' => 'Cursor Editor',
            'token_hash' => hash('sha256', $plaintext),
        ]);
    });

    it('lists active connection tokens returning prefix only without secrets', function (): void {
        enableProPlan($this->shopId);

        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
        ]);

        s2sPost('/internal/v1/mcp/tokens', ['name' => 'Client A', 'staff_ref' => 'staff_1']);
        s2sPost('/internal/v1/mcp/tokens', ['name' => 'Client B', 'staff_ref' => 'staff_1']);

        $response = s2sGet('/internal/v1/mcp/connections');

        $response->assertOk()
            ->assertJsonCount(2, 'tokens')
            ->assertJsonStructure([
                'tokens' => [
                    '*' => [
                        'id',
                        'name',
                        'token_prefix',
                        'created_by_staff_ref',
                        'is_revoked',
                        'created_at',
                    ],
                ],
            ]);

        // Verify neither plaintext nor hashes are leaked
        $tokens = $response->json('tokens');
        foreach ($tokens as $token) {
            expect($token)->not->toHaveKey('plaintext_token')
                ->and($token)->not->toHaveKey('token_hash');
        }
    });

    it('returns 404 for a malformed non-uuid connection ID on DELETE', function (): void {
        s2sDelete('/internal/v1/mcp/connections/not-a-uuid')
            ->assertStatus(404);
    });

    it('revokes an individual token by ID and evicts cache', function (): void {
        enableProPlan($this->shopId);

        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
        ]);

        $createRes = s2sPost('/internal/v1/mcp/tokens', ['name' => 'To Revoke', 'staff_ref' => 'staff_1']);
        $tokenId = $createRes->json('token.id');
        $plaintext = $createRes->json('token.plaintext_token');
        $tokenHash = hash('sha256', $plaintext);

        // Pre-prime cache
        Cache::store('redis')->put('mcp:principal:'.$tokenHash, ['shop_id' => $this->shopId], 60);
        expect(Cache::store('redis')->has('mcp:principal:'.$tokenHash))->toBeTrue();

        s2sDelete('/internal/v1/mcp/connections/'.$tokenId)
            ->assertOk()
            ->assertJson([
                'status' => 'revoked',
            ]);

        // Confirm cache eviction
        expect(Cache::store('redis')->has('mcp:principal:'.$tokenHash))->toBeFalse();

        $tokenModel = McpToken::find($tokenId);
        expect($tokenModel->isRevoked())->toBeTrue();

        // Second revoke yields 404
        s2sDelete('/internal/v1/mcp/connections/'.$tokenId)
            ->assertStatus(404);
    });

    it('withdrawing consent revokes all active tokens for the shop and evicts cache', function (): void {
        enableProPlan($this->shopId);

        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
        ]);

        $tokenARes = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Agent 1', 'staff_ref' => 'staff_1']);
        $tokenBRes = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Agent 2', 'staff_ref' => 'staff_1']);

        $tokenA = $tokenARes->json('token.id');
        $tokenB = $tokenBRes->json('token.id');
        $hashA = hash('sha256', $tokenARes->json('token.plaintext_token'));
        $hashB = hash('sha256', $tokenBRes->json('token.plaintext_token'));

        Cache::store('redis')->put('mcp:principal:'.$hashA, ['shop_id' => $this->shopId], 60);
        Cache::store('redis')->put('mcp:principal:'.$hashB, ['shop_id' => $this->shopId], 60);

        expect(McpToken::where('shop_id', $this->shopId)->active()->count())->toBe(2);

        s2sDelete('/internal/v1/mcp/consent')
            ->assertOk()
            ->assertJson([
                'status' => 'withdrawn',
            ]);

        expect(McpConsent::where('shop_id', $this->shopId)->active()->count())->toBe(0)
            ->and(McpToken::where('shop_id', $this->shopId)->active()->count())->toBe(0)
            ->and(McpToken::find($tokenA)->isRevoked())->toBeTrue()
            ->and(McpToken::find($tokenB)->isRevoked())->toBeTrue()
            ->and(Cache::store('redis')->has('mcp:principal:'.$hashA))->toBeFalse()
            ->and(Cache::store('redis')->has('mcp:principal:'.$hashB))->toBeFalse();
    });

    it('revokes tokens, withdraws consent, and evicts cache when shop is uninstalled', function (): void {
        enableProPlan($this->shopId);

        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
        ]);

        $createRes = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Agent Before Uninstall', 'staff_ref' => 'staff_1']);
        $tokenId = $createRes->json('token.id');
        $tokenHash = hash('sha256', $createRes->json('token.plaintext_token'));

        Cache::store('redis')->put('mcp:principal:'.$tokenHash, ['shop_id' => $this->shopId], 60);

        // Uninstall the shop via ingestion endpoint
        s2sPost('/internal/v1/ingestion/shop-uninstall', [
            'shopify_shop_gid' => 'gid://shopify/Shop/1',
            'uninstalled_at' => now()->toIso8601String(),
        ])->assertOk();

        // Check tokens and consent are permanently revoked
        $token = McpToken::find($tokenId);
        expect($token->isRevoked())->toBeTrue()
            ->and(McpConsent::where('shop_id', $this->shopId)->active()->count())->toBe(0)
            ->and(Cache::store('redis')->has('mcp:principal:'.$tokenHash))->toBeFalse();

        // Reinstalling the shop does NOT bring revoked tokens back
        installShop('acme.myshopify.com');

        $recheckedToken = McpToken::find($tokenId);
        expect($recheckedToken->isRevoked())->toBeTrue()
            ->and(McpConsent::where('shop_id', $this->shopId)->active()->count())->toBe(0);
    });

    it('enforces tenant isolation preventing cross-shop token modification', function (): void {
        enableProPlan($this->shopId);
        $otherShopId = installShop('other-store.myshopify.com');
        enableProPlan($otherShopId);

        s2sPost('/internal/v1/mcp/consent', [
            'staff_ref' => 'staff_1',
            'policy_version' => 'v1.0',
        ]);

        $token = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Acme Token', 'staff_ref' => 'staff_1'])->json('token.id');

        // Other shop tries to revoke Acme's token
        s2sDelete('/internal/v1/mcp/connections/'.$token, shopDomain: 'other-store.myshopify.com')
            ->assertStatus(404);

        expect(McpToken::find($token)->isRevoked())->toBeFalse();
    });
});
