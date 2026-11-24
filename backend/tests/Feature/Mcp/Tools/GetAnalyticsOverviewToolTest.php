<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Support\McpTokenService;
use App\Mcp\Tools\GetAnalyticsOverviewTool;
use App\Models\McpConsent;
use App\Models\Shop;
use App\Models\SyncRun;
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
                'shop_domain' => 'overview-tool.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/88001',
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
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/88001',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/801',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Overview Tool Test Client',
            staffRef: 'gid://shopify/Staff/801',
        );

        SyncRun::create(
            [
                'shop_id' => $this->shop->id,
                'sync_type' => SyncType::Initial,
                'status' => SyncRunStatus::Completed,
                'records_received' => 50,
                'records_processed' => 50,
                'cursor_metadata' => ['window_start' => '2026-01-01T00:00:00Z'],
                'started_at' => now()->subHours(2),
                'completed_at' => now()->subHour(),
            ]
        );
    }
);

describe(
    'GetAnalyticsOverviewTool', function (): void {
        it(
            'has proper tool metadata and annotations', function (): void {
                $tool = new GetAnalyticsOverviewTool;

                expect($tool->name())->toBe('get_analytics_overview')
                    ->and($tool->description())->toContain('overview')
                    ->and($tool->annotations())->toHaveKeys(['readOnlyHint', 'idempotentHint', 'openWorldHint'])
                    ->and($tool->annotations()['readOnlyHint'])->toBeTrue()
                    ->and($tool->annotations()['idempotentHint'])->toBeTrue()
                    ->and($tool->annotations()['openWorldHint'])->toBeFalse();

                $refClass = new ReflectionClass(GetAnalyticsOverviewTool::class);
                expect($refClass->getAttributes(IsReadOnly::class))->not->toBeEmpty()
                    ->and($refClass->getAttributes(IsIdempotent::class))->not->toBeEmpty()
                    ->and($refClass->getAttributes(IsOpenWorld::class))->not->toBeEmpty();
            }
        );

        it(
            'rejects invalid date formats with actionable guidance', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-err-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026/05/01',
                                'to' => '2026-05-10',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);

                $text = $response->json('result.content.0.text');
                expect($text)->toContain('Invalid date format')
                    ->and($text)->toContain('YYYY-MM-DD');
            }
        );

        it(
            'rejects inverted date ranges where from date is after to date', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-err-2',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-05-20',
                                'to' => '2026-05-10',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);

                $text = $response->json('result.content.0.text');
                expect($text)->toContain('cannot be after');
            }
        );

        it(
            'rejects date ranges exceeding the merchant plan limit (365 inclusive days on Pro)', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-err-3',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2025-05-10',
                                'to' => '2026-05-10', // 366 inclusive days, exceeds Pro limit of 365
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);

                $text = $response->json('result.content.0.text');
                expect($text)->toContain('Requested date range (366 days) exceeds the pro plan limit of 365 days');
            }
        );

        it(
            'returns overview metrics and preceding period comparison with full REST parity', function (): void {
                $productId = (string) Str::uuid();
                DB::table('products')->insert(
                    [
                        'id' => $productId,
                        'shop_id' => $this->shop->id,
                        'shopify_product_gid' => 'gid://shopify/Product/88001',
                        'title' => 'Chino Trousers',
                        'handle' => 'chino-trousers',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Current period metrics (2026-05-11 to 2026-05-20: 10 days)
                DB::table('product_daily_metrics')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productId,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '100.000000',
                            'discounts' => '10.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '90.000000',
                            'units_sold' => 2,
                            'order_count' => 1,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productId,
                            'metric_date' => '2026-05-15',
                            'currency_code' => 'USD',
                            'gross_sales' => '200.000000',
                            'discounts' => '20.000000',
                            'refunds' => '30.000000',
                            'net_sales' => '150.000000',
                            'units_sold' => 3,
                            'order_count' => 1,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                // Previous period metrics (2026-05-01 to 2026-05-10: 10 days)
                DB::table('product_daily_metrics')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productId,
                            'metric_date' => '2026-05-05',
                            'currency_code' => 'USD',
                            'gross_sales' => '150.000000',
                            'discounts' => '10.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '140.000000',
                            'units_sold' => 3,
                            'order_count' => 1,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                // Current period orders (2 in America/New_York: UTC offset -04:00)
                DB::table('orders')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'shopify_order_gid' => 'gid://shopify/Order/88001',
                            'order_name' => '#1001',
                            'processed_at' => '2026-05-12 14:00:00+00',
                            'financial_status' => 'paid',
                            'currency_code' => 'USD',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'shopify_order_gid' => 'gid://shopify/Order/88002',
                            'order_name' => '#1002',
                            'processed_at' => '2026-05-15 16:00:00+00',
                            'financial_status' => 'paid',
                            'currency_code' => 'USD',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                // Previous period order (1 in previous window)
                DB::table('orders')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'shopify_order_gid' => 'gid://shopify/Order/88000',
                            'order_name' => '#1000',
                            'processed_at' => '2026-05-05 14:00:00+00',
                            'financial_status' => 'paid',
                            'currency_code' => 'USD',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                // Completed sync run for metadata
                SyncRun::create(
                    [
                        'shop_id' => $this->shop->id,
                        'sync_type' => SyncType::Initial,
                        'status' => SyncRunStatus::Completed,
                        'records_received' => 20,
                        'records_processed' => 20,
                        'started_at' => now()->subHours(2),
                        'completed_at' => now()->subHour(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                // Assert exact financial metrics
                expect($data['summary'])->toHaveKey('gross_sales', '300.000000')
                    ->and($data['summary'])->toHaveKey('discounts', '30.000000')
                    ->and($data['summary'])->toHaveKey('refunds', '30.000000')
                    ->and($data['summary'])->toHaveKey('net_sales', '240.000000')
                    ->and($data['summary'])->toHaveKey('units_sold', 5)
                    ->and($data['summary'])->toHaveKey('orders', 2)
                    ->and($data['summary'])->toHaveKey('average_order_revenue', '120.000000');

                // Assert comparison metrics
                expect($data['comparison']['gross_sales'])->toHaveKey('delta', '150.000000')
                    ->and($data['comparison']['gross_sales']['direction'])->toBe('increase')
                    ->and($data['comparison']['net_sales'])->toHaveKey('delta', '100.000000')
                    ->and($data['comparison']['net_sales']['direction'])->toBe('increase')
                    ->and($data['comparison']['orders'])->toHaveKey('delta', 1)
                    ->and($data['comparison']['orders']['direction'])->toBe('increase');

                // Assert metadata context
                expect($data['metadata'])->toHaveKey('currency_code', 'USD')
                    ->and($data['metadata'])->toHaveKey('timezone', 'America/New_York')
                    ->and($data['metadata'])->toHaveKey('from', '2026-05-11')
                    ->and($data['metadata'])->toHaveKey('to', '2026-05-20')
                    ->and($data['metadata'])->toHaveKey('data_complete', true)
                    ->and($data['metadata'])->toHaveKey('plan', 'pro');
            }
        );

        it(
            'strictly isolates tenant data across different shops', function (): void {
                // Create second shop with large numbers
                $otherShop = Shop::create(
                    [
                        'shop_domain' => 'other-overview-shop.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/99002',
                        'currency_code' => 'USD',
                        'timezone' => 'America/New_York',
                        'status' => 'active',
                        'installed_at' => now(),
                    ]
                );

                $otherProductId = (string) Str::uuid();
                DB::table('products')->insert(
                    [
                        'id' => $otherProductId,
                        'shop_id' => $otherShop->id,
                        'shopify_product_gid' => 'gid://shopify/Product/99002',
                        'title' => 'Other Shop Item',
                        'handle' => 'other-shop-item',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                DB::table('product_daily_metrics')->insert(
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $otherShop->id,
                        'product_id' => $otherProductId,
                        'metric_date' => '2026-05-12',
                        'currency_code' => 'USD',
                        'gross_sales' => '999999.000000',
                        'discounts' => '0.000000',
                        'refunds' => '0.000000',
                        'net_sales' => '999999.000000',
                        'units_sold' => 1000,
                        'order_count' => 500,
                        'calculated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-iso-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                // Must be zero because current shop has no data in this date range
                expect($data['summary']['gross_sales'])->toBe('0.000000')
                    ->and($data['summary']['net_sales'])->toBe('0.000000')
                    ->and($data['summary']['units_sold'])->toBe(0);
            }
        );

        it(
            'rejects impossible calendar date 2026-02-30 with validation error', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-val-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-02-30',
                                'to' => '2026-03-05',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('Invalid date format');
            }
        );

        it(
            'rejects future date ranges on get_analytics_overview', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-val-2',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2030-01-01',
                                'to' => '2030-01-10',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('includes future dates');
            }
        );

        it(
            'returns actionable error when store has no completed sync', function (): void {
                // Delete completed sync runs for shop
                SyncRun::where('shop_id', $this->shop->id)->delete();

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-val-3',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-05-01',
                                'to' => '2026-05-10',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                $text = $response->json('result.content.0.text');
                expect($text)->toContain('Data sync has not completed')
                    ->and($text)->toContain('get_sync_status');
            }
        );

        it(
            'returns actionable error when date range is earlier than store imported history', function (): void {
                SyncRun::query()->where('shop_id', $this->shop->id)->update([
                    'cursor_metadata' => ['window_start' => '2026-05-01T04:00:00Z'],
                ]);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-val-4',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-01-01',
                                'to' => '2026-01-30',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                $text = $response->json('result.content.0.text');
                expect($text)->toContain('earlier than the store\'s imported history')
                    ->and($text)->toContain('2026-05-01');
            }
        );

        it(
            'returns zeros rather than error when dates are within sync window but before first sale', function (): void {
                // Sync window starts 60 days ago (2026-04-01), first sale occurs on 2026-05-15
                SyncRun::query()->where('shop_id', $this->shop->id)->update([
                    'cursor_metadata' => ['window_start' => '2026-04-01T04:00:00Z'],
                ]);

                $productId = (string) Str::uuid();
                DB::table('products')->insert([
                    'id' => $productId,
                    'shop_id' => $this->shop->id,
                    'shopify_product_gid' => 'gid://shopify/Product/88099',
                    'title' => 'Sample Product',
                    'handle' => 'sample-product',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('product_daily_metrics')->insert([
                    'id' => (string) Str::uuid(),
                    'shop_id' => $this->shop->id,
                    'product_id' => $productId,
                    'metric_date' => '2026-05-15',
                    'currency_code' => 'USD',
                    'gross_sales' => '100.000000',
                    'discounts' => '0.000000',
                    'refunds' => '0.000000',
                    'net_sales' => '100.000000',
                    'units_sold' => 1,
                    'order_count' => 1,
                    'calculated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Query 2026-04-10 to 2026-04-20: inside sync window, but before first sale
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-val-5',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-04-10',
                                'to' => '2026-04-20',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);
                $data = json_decode($response->json('result.content.0.text'), true);
                expect($data['summary']['gross_sales'])->toBe('0.000000')
                    ->and($data['summary']['net_sales'])->toBe('0.000000')
                    ->and($data['metadata']['data_complete'])->toBeTrue();
            }
        );

        it(
            'marks data_complete as false when date range partly predates sync window', function (): void {
                SyncRun::query()->where('shop_id', $this->shop->id)->update([
                    'cursor_metadata' => ['window_start' => '2026-04-01T04:00:00Z'],
                ]);

                // Range starts 2026-03-15 (before window_start) and ends 2026-04-15 (after window_start)
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ov-val-6',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-03-15',
                                'to' => '2026-04-15',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);
                $data = json_decode($response->json('result.content.0.text'), true);
                expect($data['metadata']['data_complete'])->toBeFalse();
            }
        );
    }
);
