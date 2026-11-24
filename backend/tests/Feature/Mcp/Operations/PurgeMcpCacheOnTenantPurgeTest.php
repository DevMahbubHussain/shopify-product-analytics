<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Events\TenantPurgeRequested;
use App\Listeners\PurgeMcpCacheOnTenantPurge;
use App\Mcp\Support\McpTokenService;
use App\Models\McpAuditLog;
use App\Models\McpConsent;
use App\Models\McpToken;
use App\Models\Shop;
use App\Services\TenantPurgeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'purge-mcp-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77095',
                'currency_code' => 'USD',
                'timezone' => 'America/Chicago',
                'status' => 'active',
                'installed_at' => now()->subDays(30),
            ]
        );

        DB::table('subscriptions')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77095',
                'plan_key' => 'pro',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->consent = McpConsent::create(
            [
                'shop_id' => $this->shop->id,
                'platform' => Platform::Shopify,
                'granted_by_staff_ref' => 'gid://shopify/Staff/795',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Purge Test Client',
            staffRef: 'gid://shopify/Staff/795',
        );

        $this->auditLog = McpAuditLog::create(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'connection_type' => 'token',
                'connection_id' => $this->generatedToken->token->id,
                'method' => 'tools/call',
                'target_name' => 'get_sync_status',
                'status' => 'success',
                'duration_ms' => 12,
                'client_name' => 'Purge Test Runner',
                'protocol_version' => '2026-07-28',
                'correlation_id' => (string) Str::uuid(),
                'created_at' => CarbonImmutable::now(),
            ]
        );
    }
);

describe(
    'PurgeMcpCacheOnTenantPurge', function (): void {
        it(
            'forgets redis cached credentials and locks when TenantPurgeRequested is dispatched', function (): void {
                $tokenHash = $this->generatedToken->token->token_hash;
                $tokenId = $this->generatedToken->token->id;

                $principalCacheKey = 'mcp:principal:'.$tokenHash;
                $lastUsedCacheKey = 'mcp:last_used:'.$tokenId;

                Cache::store('redis')->put($principalCacheKey, ['shop_id' => $this->shop->id], 300);
                Cache::store('redis')->put($lastUsedCacheKey, true, 300);

                expect(Cache::store('redis')->has($principalCacheKey))->toBeTrue()
                    ->and(Cache::store('redis')->has($lastUsedCacheKey))->toBeTrue();

                $listener = new PurgeMcpCacheOnTenantPurge;
                $listener->handle(new TenantPurgeRequested($this->shop->id));

                expect(Cache::store('redis')->has($principalCacheKey))->toBeFalse()
                    ->and(Cache::store('redis')->has($lastUsedCacheKey))->toBeFalse();
            }
        );

        it(
            'cascades deletion of tokens, consents, and audit logs during full tenant purge', function (): void {
                $tokenHash = $this->generatedToken->token->token_hash;
                $tokenId = $this->generatedToken->token->id;

                Cache::store('redis')->put('mcp:principal:'.$tokenHash, ['shop_id' => $this->shop->id], 300);
                Cache::store('redis')->put('mcp:last_used:'.$tokenId, true, 300);

                $purgeService = app(TenantPurgeService::class);
                $purgeService->purgeByDomain($this->shop->shop_domain);

                // Verify Redis cache is cleared
                expect(Cache::store('redis')->has('mcp:principal:'.$tokenHash))->toBeFalse()
                    ->and(Cache::store('redis')->has('mcp:last_used:'.$tokenId))->toBeFalse();

                // Verify Database records are deleted via cascade
                expect(Shop::find($this->shop->id))->toBeNull()
                    ->and(McpToken::find($tokenId))->toBeNull()
                    ->and(McpConsent::find($this->consent->id))->toBeNull()
                    ->and(McpAuditLog::find($this->auditLog->id))->toBeNull();
            }
        );
    }
);
