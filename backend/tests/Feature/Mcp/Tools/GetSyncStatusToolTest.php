<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Support\McpTokenService;
use App\Mcp\Tools\GetSyncStatusTool;
use App\Models\McpConsent;
use App\Models\Shop;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'tool-test.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77001',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now(),
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
            name: 'Sync Tool Test Client',
            staffRef: 'gid://shopify/Staff/701',
        );
    }
);

describe(
    'GetSyncStatusTool', function (): void {
        it(
            'has proper tool metadata and annotations', function (): void {
                $tool = new GetSyncStatusTool;

                expect($tool->name())->toBe('get_sync_status')
                    ->and($tool->description())->toContain('freshness')
                    ->and($tool->annotations())->toHaveKeys(['readOnlyHint', 'idempotentHint', 'openWorldHint'])
                    ->and($tool->annotations()['readOnlyHint'])->toBeTrue()
                    ->and($tool->annotations()['idempotentHint'])->toBeTrue()
                    ->and($tool->annotations()['openWorldHint'])->toBeFalse();

                $refClass = new ReflectionClass(GetSyncStatusTool::class);
                expect($refClass->getAttributes(IsReadOnly::class))->not->toBeEmpty()
                    ->and($refClass->getAttributes(IsIdempotent::class))->not->toBeEmpty()
                    ->and($refClass->getAttributes(IsOpenWorld::class))->not->toBeEmpty();
            }
        );

        it(
            'returns empty/no-sync state when store has never run a sync', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-sync-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_sync_status',
                            'arguments' => [],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_sync_status',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false)
                    ->assertJsonPath('result.content.0.type', 'text');

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                expect($data)->toHaveKey('status', 'NO_SYNC')
                    ->and($data)->toHaveKey('data_complete', false)
                    ->and($data)->toHaveKey('last_synced_at', null)
                    ->and($data)->toHaveKey('currency_code', 'USD')
                    ->and($data)->toHaveKey('timezone', 'America/New_York')
                    ->and($data)->toHaveKey('plan', 'pro');
            }
        );

        it(
            'returns completed sync status and history notice when sync has succeeded', function (): void {
                $completedTime = CarbonImmutable::parse('2026-11-20T12:00:00Z');

                SyncRun::create(
                    [
                        'shop_id' => $this->shop->id,
                        'sync_type' => SyncType::Initial,
                        'status' => SyncRunStatus::Completed,
                        'records_received' => 150,
                        'records_processed' => 150,
                        'started_at' => $completedTime->subMinutes(5),
                        'completed_at' => $completedTime,
                        'history_notice' => 'All historical orders synced (365 days available).',
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-sync-2',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_sync_status',
                            'arguments' => [],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_sync_status',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                expect($data)->toHaveKey('status', 'COMPLETED')
                    ->and($data)->toHaveKey('data_complete', true)
                    ->and($data['last_synced_at'])->toBe($completedTime->toIso8601String())
                    ->and($data['history_notice'])->toBe('All historical orders synced (365 days available).');
            }
        );
    }
);
