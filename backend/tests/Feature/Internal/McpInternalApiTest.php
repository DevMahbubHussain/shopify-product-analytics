<?php

declare(strict_types=1);

use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Events\TenantDeactivated;
use App\Jobs\TouchMcpTokenLastUsed;
use App\Listeners\RevokeMcpConnectionsOnTenantDeactivated;
use App\Models\McpConsent;
use App\Models\McpToken;
use App\Models\Shop;
use App\Models\SyncRun;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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

function microsOf(string $table, string $column, string $id): ?string
{
    return DB::table($table)->where('id', $id)
        ->value(DB::raw("to_char({$column} AT TIME ZONE 'UTC', 'US')"));
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

    it('excludes revoked and expired tokens from the connections list', function (): void {
        enableProPlan($this->shopId);
        s2sPost('/internal/v1/mcp/consent', ['staff_ref' => 'staff_1', 'policy_version' => 'v1.0']);

        $keep = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Active', 'staff_ref' => 'staff_1'])->json('token.id');
        $revoked = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Revoked', 'staff_ref' => 'staff_1'])->json('token.id');
        $expired = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Expired', 'staff_ref' => 'staff_1'])->json('token.id');

        s2sDelete("/internal/v1/mcp/connections/{$revoked}")->assertOk();
        McpToken::query()->whereKey($expired)->update(['expires_at' => now()->subMinute()]);

        s2sGet('/internal/v1/mcp/connections')
            ->assertOk()
            ->assertJsonCount(1, 'tokens')
            ->assertJsonPath('tokens.0.id', $keep);
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

describe('MCP timestamp precision', function (): void {
    beforeEach(function (): void {
        $this->instant = CarbonImmutable::parse('2026-11-25 10:00:00.123456', 'UTC');
        CarbonImmutable::setTestNow($this->instant);
        Carbon::setTestNow($this->instant);
        enableProPlan($this->shopId);
    });

    afterEach(function (): void {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    });

    it('keeps microseconds on consent grant and token creation', function (): void {
        $consentId = s2sPost('/internal/v1/mcp/consent', ['staff_ref' => 'staff_1', 'policy_version' => 'v1.0'])
            ->assertCreated()->json('consent.id');
        $tokenId = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Precise', 'staff_ref' => 'staff_1'])
            ->assertCreated()->json('token.id');

        expect(microsOf('mcp_consents', 'granted_at', $consentId))->toBe('123456')
            ->and(microsOf('mcp_consents', 'created_at', $consentId))->toBe('123456')
            ->and(microsOf('mcp_tokens', 'created_at', $tokenId))->toBe('123456');
    });

    it('keeps microseconds on single token revoke and on consent withdrawal', function (): void {
        s2sPost('/internal/v1/mcp/consent', ['staff_ref' => 'staff_1', 'policy_version' => 'v1.0']);
        $revokedId = s2sPost('/internal/v1/mcp/tokens', ['name' => 'One', 'staff_ref' => 'staff_1'])->json('token.id');
        $bulkId = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Two', 'staff_ref' => 'staff_1'])->json('token.id');

        s2sDelete("/internal/v1/mcp/connections/{$revokedId}")->assertOk();
        s2sDelete('/internal/v1/mcp/consent')->assertOk();

        $consentId = (string) DB::table('mcp_consents')->where('shop_id', $this->shopId)->value('id');

        expect(microsOf('mcp_tokens', 'revoked_at', $revokedId))->toBe('123456')
            ->and(microsOf('mcp_tokens', 'revoked_at', $bulkId))->toBe('123456')
            ->and(microsOf('mcp_consents', 'withdrawn_at', $consentId))->toBe('123456');
    });

    it('keeps microseconds when recording last use and on tenant deactivation', function (): void {
        s2sPost('/internal/v1/mcp/consent', ['staff_ref' => 'staff_1', 'policy_version' => 'v1.0']);
        $tokenId = s2sPost('/internal/v1/mcp/tokens', ['name' => 'Used', 'staff_ref' => 'staff_1'])->json('token.id');

        (new TouchMcpTokenLastUsed($tokenId, $this->instant))->handle();
        (new RevokeMcpConnectionsOnTenantDeactivated)->handle(new TenantDeactivated($this->shopId, $this->instant));

        $consentId = (string) DB::table('mcp_consents')->where('shop_id', $this->shopId)->value('id');

        expect(microsOf('mcp_tokens', 'last_used_at', $tokenId))->toBe('123456')
            ->and(microsOf('mcp_tokens', 'revoked_at', $tokenId))->toBe('123456')
            ->and(microsOf('mcp_consents', 'withdrawn_at', $consentId))->toBe('123456');
    });
});

describe('MCP status store context', function (): void {
    function completedInitialSync(string $shopId, string $windowStart): void
    {
        SyncRun::query()->create([
            'shop_id' => $shopId,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'cursor_metadata' => ['window_start' => $windowStart],
        ]);
    }

    function dailyMetricOn(string $shopId, string $metricDate): void
    {
        $productId = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $productId,
            'shop_id' => $shopId,
            'shopify_product_gid' => 'gid://shopify/Product/'.random_int(1000, 9999),
            'title' => 'Seeded',
            'handle' => 'seeded-'.random_int(1000, 9999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'product_id' => $productId,
            'metric_date' => $metricDate,
            'currency_code' => 'USD',
            'gross_sales' => '10.000000',
            'discounts' => '0.000000',
            'refunds' => '0.000000',
            'net_sales' => '10.000000',
            'units_sold' => 1,
            'order_count' => 1,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    it('returns timezone, currency and the sync window start in store time', function (): void {
        completedInitialSync($this->shopId, '2026-09-26T04:00:00Z');

        s2sGet('/internal/v1/mcp/status')
            ->assertOk()
            ->assertJsonPath('store.timezone', 'America/New_York')
            ->assertJsonPath('store.currency_code', 'USD')
            ->assertJsonPath('store.history_start', '2026-09-26');
    });

    it('converts a window start just after UTC midnight to the previous store day', function (): void {
        completedInitialSync($this->shopId, '2026-09-26T02:00:00Z');

        s2sGet('/internal/v1/mcp/status')->assertJsonPath('store.history_start', '2026-09-25');
    });

    it('uses the earliest metric date when it is before the sync window', function (): void {
        completedInitialSync($this->shopId, '2026-09-26T04:00:00Z');
        dailyMetricOn($this->shopId, '2026-09-20');

        s2sGet('/internal/v1/mcp/status')->assertJsonPath('store.history_start', '2026-09-20');
    });

    it('falls back to sixty days before install in store time when no sync has completed', function (): void {
        $installedAt = CarbonImmutable::parse((string) DB::table('shops')->where('id', $this->shopId)->value('installed_at'));
        $expected = $installedAt->setTimezone('America/New_York')->subDays(60)->toDateString();

        s2sGet('/internal/v1/mcp/status')->assertJsonPath('store.history_start', $expected);
    });
});
