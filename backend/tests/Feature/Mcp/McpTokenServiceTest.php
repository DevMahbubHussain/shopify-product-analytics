<?php

declare(strict_types=1);

use App\Data\Mcp\GeneratedMcpToken;
use App\Data\Mcp\McpConsentData;
use App\Data\Mcp\StoreMcpTokenData;
use App\Mcp\Support\McpTokenService;
use App\Models\McpToken;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'alpha-test.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/10001',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now(),
            ]
        );

        $this->service = app(McpTokenService::class);
    }
);

describe(
    'McpTokenService', function (): void {
        it(
            'generates a token with spa_mcp_ prefix and 256 bits of entropy', function (): void {
                $result = $this->service->createToken(
                    shop: $this->shop,
                    name: 'Cursor AI Desktop',
                    staffRef: 'gid://shopify/Staff/101',
                );

                expect($result->plaintextToken)->toStartWith('spa_mcp_')
                    ->and(strlen($result->plaintextToken))->toBe(51) // 8 char prefix + 43 char base62 body
                    ->and($result->token)->toBeInstanceOf(McpToken::class)
                    ->and($result->token->name)->toBe('Cursor AI Desktop')
                    ->and($result->token->shop_id)->toBe($this->shop->id)
                    ->and($result->token->token_prefix)->toBe(substr($result->plaintextToken, 0, 16))
                    ->and($result->token->token_hash)->toBe(hash('sha256', $result->plaintextToken));

                // Confirm plaintext is NOT stored anywhere in the database
                $this->assertDatabaseHas(
                    'mcp_tokens', [
                        'id' => $result->token->id,
                        'name' => 'Cursor AI Desktop',
                        'token_hash' => hash('sha256', $result->plaintextToken),
                    ]
                );

                $this->assertDatabaseMissing(
                    'mcp_tokens', [
                        'token_hash' => $result->plaintextToken,
                    ]
                );
            }
        );

        it(
            'resolves a valid plaintext token to its active model', function (): void {
                $result = $this->service->createToken(
                    shop: $this->shop,
                    name: 'Claude Connector',
                    staffRef: 'gid://shopify/Staff/102',
                );

                $resolved = $this->service->findValidToken($result->plaintextToken);

                expect($resolved)->not->toBeNull()
                    ->and($resolved->id)->toBe($result->token->id)
                    ->and($resolved->shop_id)->toBe($this->shop->id);
            }
        );

        it(
            'returns null when token does not exist', function (): void {
                $resolved = $this->service->findValidToken('spa_mcp_nonexistenttoken1234567890abcdefghijklm');

                expect($resolved)->toBeNull();
            }
        );

        it(
            'returns null when token is revoked', function (): void {
                $result = $this->service->createToken(
                    shop: $this->shop,
                    name: 'Revoked Agent',
                    staffRef: 'gid://shopify/Staff/103',
                );

                $result->token->revoke();

                $resolved = $this->service->findValidToken($result->plaintextToken);

                expect($resolved)->toBeNull();
            }
        );

        it(
            'returns null when token is expired', function (): void {
                $result = $this->service->createToken(
                    shop: $this->shop,
                    name: 'Expired Agent',
                    staffRef: 'gid://shopify/Staff/104',
                    expiresAt: CarbonImmutable::now()->subMinute(),
                );

                $resolved = $this->service->findValidToken($result->plaintextToken);

                expect($resolved)->toBeNull();
            }
        );

        it(
            'revokes a token for a specific shop tenant', function (): void {
                $result = $this->service->createToken(
                    shop: $this->shop,
                    name: 'To Revoke',
                    staffRef: 'gid://shopify/Staff/105',
                );

                expect($result->token->isRevoked())->toBeFalse();

                Cache::store('redis')->put('mcp:principal:'.$result->token->token_hash, ['shop_id' => $this->shop->id], 60);
                expect(Cache::store('redis')->has('mcp:principal:'.$result->token->token_hash))->toBeTrue();

                $revoked = $this->service->revokeToken($this->shop, $result->token->id);

                expect($revoked)->toBeTrue()
                    ->and(Cache::store('redis')->has('mcp:principal:'.$result->token->token_hash))->toBeFalse();

                $refreshed = $result->token->fresh();
                expect($refreshed->isRevoked())->toBeTrue()
                    ->and($this->service->findValidToken($result->plaintextToken))->toBeNull();
            }
        );

        it(
            'cannot revoke a token belonging to another shop', function (): void {
                $otherShop = Shop::create(
                    [
                        'shop_domain' => 'beta-test.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/20002',
                        'currency_code' => 'USD',
                        'timezone' => 'America/Chicago',
                        'status' => 'active',
                        'installed_at' => now(),
                    ]
                );

                $result = $this->service->createToken(
                    shop: $this->shop,
                    name: 'Shop A Token',
                    staffRef: 'staff_1',
                );

                // Attempt revoke from Shop B
                $revoked = $this->service->revokeToken($otherShop, $result->token->id);

                expect($revoked)->toBeFalse()
                    ->and($result->token->fresh()->isRevoked())->toBeFalse();
            }
        );

        it('supports standalone PSR-4 autoloading for all DTOs without loading caller files', function (): void {
            expect(class_exists(GeneratedMcpToken::class))->toBeTrue()
                ->and(class_exists(McpConsentData::class))->toBeTrue()
                ->and(class_exists(StoreMcpTokenData::class))->toBeTrue();
        });
    }
);
