<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Resources\SyncStatusResource;
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
                'shop_domain' => 'sync-resource-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77011',
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
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77011',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/711',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Sync Resource Client',
            staffRef: 'gid://shopify/Staff/711',
        );
    }
);

describe(
    'SyncStatusResource', function (): void {
        it(
            'has proper resource metadata and uri attributes', function (): void {
                $resource = new SyncStatusResource;

                expect($resource->name())->toBe('sync_status')
                    ->and($resource->title())->toBe('Sync Status')
                    ->and($resource->uri())->toBe('analytics://shop/sync-status')
                    ->and($resource->mimeType())->toBe('application/json')
                    ->and($resource->description())->toContain('data ingestion status');
            }
        );

        it(
            'lists the resource via resources/list JSON-RPC method', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'sync-res-list-1',
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

                $response->assertStatus(200);
                $uris = collect($response->json('result.resources'))->pluck('uri')->all();
                expect($uris)->toContain('analytics://shop/sync-status');
            }
        );

        it(
            'reads completed sync status via resources/read with full counters and platform neutrality', function (): void {
                SyncRun::create(
                    [
                        'shop_id' => $this->shop->id,
                        'sync_type' => SyncType::Initial,
                        'status' => SyncRunStatus::Completed,
                        'records_received' => 250,
                        'records_processed' => 250,
                        'history_notice' => 'Initial 60-day historical order sync complete.',
                        'cursor_metadata' => ['window_start' => now()->subDays(60)->toIso8601String()],
                        'started_at' => now()->subHours(2),
                        'completed_at' => now()->subHour(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'sync-res-read-1',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://shop/sync-status',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://shop/sync-status',
                    ]
                );

                $response->assertStatus(200);

                $content = $response->json('result.contents.0');
                expect($content['uri'])->toBe('analytics://shop/sync-status')
                    ->and($content['mimeType'])->toBe('application/json');

                $data = json_decode($content['text'], true);
                expect($data['status'])->toBe('COMPLETED')
                    ->and($data['sync_type'])->toBe('initial')
                    ->and($data['records_received'])->toBe(250)
                    ->and($data['records_processed'])->toBe(250)
                    ->and($data['data_complete'])->toBeTrue()
                    ->and($data['last_synced_at'])->not->toBeNull()
                    ->and($data['history_notice'])->toBe('Initial 60-day historical order sync complete.')
                    ->and($data['currency_code'])->toBe('USD')
                    ->and($data['timezone'])->toBe('America/Chicago')
                    ->and($data['plan'])->toBe('pro');

                // Zero platform GIDs
                expect($content['text'])->not->toContain('gid://shopify');
            }
        );

        it(
            'reads empty sync state when store has no sync runs', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'sync-res-read-empty',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://shop/sync-status',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://shop/sync-status',
                    ]
                );

                $response->assertStatus(200);
                $data = json_decode($response->json('result.contents.0.text'), true);

                expect($data['status'])->toBe('NO_SYNC')
                    ->and($data['data_complete'])->toBeFalse()
                    ->and($data['last_synced_at'])->toBeNull()
                    ->and($data['records_received'])->toBe(0)
                    ->and($data['records_processed'])->toBe(0);
            }
        );

        it(
            'strictly isolates sync status across different stores', function (): void {
                $shopOther = Shop::create(
                    [
                        'shop_domain' => 'sync-other.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/77012',
                        'currency_code' => 'CAD',
                        'timezone' => 'America/Toronto',
                        'status' => 'active',
                        'installed_at' => now()->subDays(10),
                    ]
                );

                DB::table('subscriptions')->insert(
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $shopOther->id,
                        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77012',
                        'plan_key' => 'pro',
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                McpConsent::create(
                    [
                        'shop_id' => $shopOther->id,
                        'platform' => Platform::Shopify,
                        'granted_by_staff_ref' => 'gid://shopify/Staff/712',
                        'policy_version' => 'v1.0',
                        'granted_at' => now(),
                    ]
                );

                $tokenOther = $this->tokenService->createToken(
                    shop: $shopOther,
                    name: 'Other Sync Client',
                    staffRef: 'gid://shopify/Staff/712',
                );

                SyncRun::create(
                    [
                        'shop_id' => $shopOther->id,
                        'sync_type' => SyncType::Initial,
                        'status' => SyncRunStatus::Running,
                        'records_received' => 500,
                        'records_processed' => 120,
                        'started_at' => now()->subMinutes(10),
                    ]
                );

                $responseOther = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'sync-res-other',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://shop/sync-status',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$tokenOther->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://shop/sync-status',
                    ]
                );

                $dataOther = json_decode($responseOther->json('result.contents.0.text'), true);
                expect($dataOther['status'])->toBe('RUNNING')
                    ->and($dataOther['records_received'])->toBe(500)
                    ->and($dataOther['records_processed'])->toBe(120)
                    ->and($dataOther['data_complete'])->toBeFalse()
                    ->and($dataOther['currency_code'])->toBe('CAD');
            }
        );
    }
);
