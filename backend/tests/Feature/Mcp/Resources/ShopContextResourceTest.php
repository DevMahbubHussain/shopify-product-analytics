<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Resources\ShopContextResource;
use App\Mcp\Support\McpTokenService;
use App\Models\McpConsent;
use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'context-resource-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77001',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now()->subDays(90),
            ]
        );

        DB::table('subscriptions')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77001',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/701',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Context Resource Client',
            staffRef: 'gid://shopify/Staff/701',
        );

        SyncRun::create(
            [
                'shop_id' => $this->shop->id,
                'sync_type' => SyncType::Initial,
                'status' => SyncRunStatus::Completed,
                'records_received' => 100,
                'records_processed' => 100,
                'cursor_metadata' => ['window_start' => now()->subDays(60)->toIso8601String()],
                'started_at' => now()->subHours(2),
                'completed_at' => now()->subHour(),
            ]
        );
    }
);

describe(
    'ShopContextResource', function (): void {
        it(
            'has proper resource metadata and uri attributes', function (): void {
                $resource = new ShopContextResource;

                expect($resource->name())->toBe('shop_context')
                    ->and($resource->title())->toBe('Shop Context')
                    ->and($resource->uri())->toBe('analytics://shop/context')
                    ->and($resource->mimeType())->toBe('application/json')
                    ->and($resource->description())->toContain('analytics configuration');
            }
        );

        it(
            'lists the resource via resources/list JSON-RPC method', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'res-list-1',
                        'method' => 'resources/list',
                        'params' => [
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/list',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.resources.0.name', 'shop_context')
                    ->assertJsonPath('result.resources.0.uri', 'analytics://shop/context')
                    ->assertJsonPath('result.resources.0.mimeType', 'application/json');
            }
        );

        it(
            'reads shop context via resources/read with full tenant metadata and platform neutrality', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'res-read-1',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://shop/context',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://shop/context',
                    ]
                );

                $response->assertStatus(200);

                $content = $response->json('result.contents.0');
                expect($content['uri'])->toBe('analytics://shop/context')
                    ->and($content['mimeType'])->toBe('application/json');

                $data = json_decode($content['text'], true);
                expect($data)->not->toHaveKey('shop_domain')
                    ->and($data['currency_code'])->toBe('USD')
                    ->and($data['timezone'])->toBe('America/New_York')
                    ->and($data['plan'])->toBe('pro')
                    ->and($data['max_history_days'])->toBe(365)
                    ->and($data['data_complete'])->toBeTrue()
                    ->and($data['last_synced_at'])->not->toBeEmpty();

                // Platform neutrality: zero Shopify GIDs in response text or keys
                expect($content['text'])->not->toContain('gid://shopify')
                    ->and($content['text'])->not->toContain('shopify_shop_gid')
                    ->and($content['text'])->not->toContain('myshopify')
                    ->and(strtolower($content['text']))->not->toContain('shopify');
            }
        );

        it(
            'strictly isolates shop context across different tenants', function (): void {
                $shopB = Shop::create(
                    [
                        'shop_domain' => 'tenant-beta.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/77002',
                        'currency_code' => 'EUR',
                        'timezone' => 'Europe/Berlin',
                        'status' => 'active',
                        'installed_at' => now()->subDays(30),
                    ]
                );

                DB::table('subscriptions')->insert(
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $shopB->id,
                        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77002',
                        'plan_key' => 'pro',
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                McpConsent::create(
                    [
                        'shop_id' => $shopB->id,
                        'platform' => Platform::Shopify,
                        'granted_by_staff_ref' => 'gid://shopify/Staff/702',
                        'policy_version' => 'v1.0',
                        'granted_at' => now(),
                    ]
                );

                $tokenB = $this->tokenService->createToken(
                    shop: $shopB,
                    name: 'Tenant Beta Client',
                    staffRef: 'gid://shopify/Staff/702',
                );

                $responseB = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'res-read-b',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://shop/context',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$tokenB->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://shop/context',
                    ]
                );

                $dataB = json_decode($responseB->json('result.contents.0.text'), true);
                expect($dataB)->not->toHaveKey('shop_domain')
                    ->and($dataB['currency_code'])->toBe('EUR')
                    ->and($dataB['timezone'])->toBe('Europe/Berlin')
                    ->and($responseB->json('result.contents.0.text'))->not->toContain('context-resource-store.myshopify.com');
            }
        );
    }
);
