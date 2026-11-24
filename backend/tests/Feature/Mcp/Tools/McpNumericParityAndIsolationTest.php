<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
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
        $this->shopA = Shop::create(
            [
                'shop_domain' => 'store-alpha.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/10001',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now(),
            ]
        );

        $this->shopB = Shop::create(
            [
                'shop_domain' => 'store-beta.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/10002',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now(),
            ]
        );

        foreach ([$this->shopA, $this->shopB] as $shop) {
            DB::table('subscriptions')->insert(
                [
                    'id' => (string) Str::uuid(),
                    'shop_id' => $shop->id,
                    'shopify_subscription_gid' => 'gid://shopify/AppSubscription/'.rand(10000, 99999),
                    'plan_key' => 'pro',
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            McpConsent::create(
                [
                    'shop_id' => $shop->id,
                    'platform' => Platform::Shopify,
                    'granted_by_staff_ref' => 'gid://shopify/Staff/'.rand(1000, 9999),
                    'policy_version' => 'v1.0',
                    'granted_at' => now(),
                ]
            );
        }

        $tokenService = app(McpTokenService::class);
        $this->tokenA = $tokenService->createToken($this->shopA, 'Alpha Client', 'gid://shopify/Staff/101')->plaintextToken;
        $this->tokenB = $tokenService->createToken($this->shopB, 'Beta Client', 'gid://shopify/Staff/102')->plaintextToken;

        // Seed Store A dataset
        $this->productA = (string) Str::uuid();
        DB::table('products')->insert(
            [
                'id' => $this->productA,
                'shop_id' => $this->shopA->id,
                'shopify_product_gid' => 'gid://shopify/Product/1001',
                'title' => 'Alpha Leather Wallet',
                'handle' => 'alpha-leather-wallet',
                'vendor' => 'Alpha Craft',
                'product_type' => 'Leather Goods',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('product_daily_metrics')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopA->id,
                'product_id' => $this->productA,
                'metric_date' => '2026-05-15',
                'currency_code' => 'USD',
                'gross_sales' => '1250.500000',
                'discounts' => '150.250000',
                'refunds' => '100.000000',
                'net_sales' => '1000.250000',
                'units_sold' => 25,
                'order_count' => 10,
                'calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Seed Store B dataset with different numbers
        $this->productB = (string) Str::uuid();
        DB::table('products')->insert(
            [
                'id' => $this->productB,
                'shop_id' => $this->shopB->id,
                'shopify_product_gid' => 'gid://shopify/Product/2001',
                'title' => 'Beta Titanium Pen',
                'handle' => 'beta-titanium-pen',
                'vendor' => 'Beta Metalworks',
                'product_type' => 'Stationery',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('product_daily_metrics')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopB->id,
                'product_id' => $this->productB,
                'metric_date' => '2026-05-15',
                'currency_code' => 'USD',
                'gross_sales' => '4500.000000',
                'discounts' => '500.000000',
                'refunds' => '250.000000',
                'net_sales' => '3750.000000',
                'units_sold' => 45,
                'order_count' => 20,
                'calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        foreach ([$this->shopA, $this->shopB] as $shop) {
            SyncRun::create(
                [
                    'shop_id' => $shop->id,
                    'sync_type' => SyncType::Initial,
                    'status' => SyncRunStatus::Completed,
                    'records_received' => 50,
                    'records_processed' => 50,
                    'cursor_metadata' => ['window_start' => '2026-01-01T00:00:00Z'],
                    'started_at' => now()->subHour(),
                    'completed_at' => now(),
                ]
            );
        }
    }
);

describe(
    'MCP Tools: Exact Numeric Parity with REST API', function (): void {
        it(
            'guarantees cent-for-cent numeric parity on overview analytics', function (): void {
                // Query REST endpoint
                $restResponse = s2sGet('/api/v1/analytics/overview?from=2026-05-01&to=2026-05-31', $this->shopA->shop_domain);
                $restResponse->assertStatus(200);
                $restData = $restResponse->json('data');

                // Query MCP tool
                $mcpResponse = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-parity-ov',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-05-01',
                                'to' => '2026-05-31',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenA,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $mcpResponse->assertStatus(200);
                $mcpData = json_decode($mcpResponse->json('result.content.0.text'), true);

                // Assert exact decimal equality between REST and MCP
                expect($mcpData['summary']['gross_sales'])->toBe($restData['summary']['gross_sales'])
                    ->and($mcpData['summary']['discounts'])->toBe($restData['summary']['discounts'])
                    ->and($mcpData['summary']['refunds'])->toBe($restData['summary']['refunds'])
                    ->and($mcpData['summary']['net_sales'])->toBe($restData['summary']['net_sales'])
                    ->and($mcpData['summary']['units_sold'])->toBe($restData['summary']['units_sold'])
                    ->and($mcpData['summary']['average_order_revenue'])->toBe($restData['summary']['average_order_revenue']);
            }
        );

        it(
            'guarantees cent-for-cent numeric parity on product performance listing', function (): void {
                // Query REST endpoint
                $restResponse = s2sGet('/api/v1/products?from=2026-05-01&to=2026-05-31', $this->shopA->shop_domain);
                $restResponse->assertStatus(200);
                $restItems = $restResponse->json('data');

                // Query MCP tool
                $mcpResponse = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-parity-prod',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-01',
                                'to' => '2026-05-31',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenA,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $mcpResponse->assertStatus(200);
                $mcpItems = json_decode($mcpResponse->json('result.content.0.text'), true)['items'];

                expect($mcpItems)->toHaveCount(count($restItems))
                    ->and($mcpItems[0]['id'])->toBe($restItems[0]['id'])
                    ->and($mcpItems[0]['title'])->toBe($restItems[0]['title'])
                    ->and($mcpItems[0]['gross_sales'])->toBe($restItems[0]['gross_sales'])
                    ->and($mcpItems[0]['net_sales'])->toBe($restItems[0]['net_sales'])
                    ->and($mcpItems[0]['units_sold'])->toBe($restItems[0]['units_sold']);
            }
        );

        it(
            'guarantees cent-for-cent numeric parity on sales trend time-series', function (): void {
                // Query REST endpoint
                $restResponse = s2sGet('/api/v1/analytics/sales-trend?from=2026-05-14&to=2026-05-16&interval=day', $this->shopA->shop_domain);
                $restResponse->assertStatus(200);
                $restPoints = $restResponse->json('data.points');

                // Query MCP tool
                $mcpResponse = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-parity-tr',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_sales_trend',
                            'arguments' => [
                                'from' => '2026-05-14',
                                'to' => '2026-05-16',
                                'interval' => 'day',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenA,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_sales_trend',
                    ]
                );

                $mcpResponse->assertStatus(200);
                $mcpPoints = json_decode($mcpResponse->json('result.content.0.text'), true)['points'];

                expect($mcpPoints)->toHaveCount(count($restPoints));

                foreach ($mcpPoints as $idx => $mcpPoint) {
                    expect($mcpPoint['date'])->toBe($restPoints[$idx]['date'])
                        ->and($mcpPoint['gross_sales'])->toBe($restPoints[$idx]['gross_sales'])
                        ->and($mcpPoint['net_sales'])->toBe($restPoints[$idx]['net_sales'])
                        ->and($mcpPoint['units_sold'])->toBe($restPoints[$idx]['units_sold']);
                }
            }
        );

        it(
            'guarantees cent-for-cent numeric parity on product detail analytics', function (): void {
                // Query REST endpoint
                $restResponse = s2sGet('/api/v1/products/'.$this->productA.'/analytics?from=2026-05-01&to=2026-05-31', $this->shopA->shop_domain);
                $restResponse->assertStatus(200);
                $restData = $restResponse->json('data');

                // Query MCP tool
                $mcpResponse = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-parity-det',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'product_id' => $this->productA,
                                'from' => '2026-05-01',
                                'to' => '2026-05-31',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenA,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $mcpResponse->assertStatus(200);
                $mcpData = json_decode($mcpResponse->json('result.content.0.text'), true);

                expect($mcpData['product']['id'])->toBe($restData['product']['id'])
                    ->and($mcpData['product']['title'])->toBe($restData['product']['title'])
                    ->and($mcpData['summary']['gross_sales'])->toBe($restData['summary']['gross_sales'])
                    ->and($mcpData['summary']['discounts'])->toBe($restData['summary']['discounts'])
                    ->and($mcpData['summary']['refunds'])->toBe($restData['summary']['refunds'])
                    ->and($mcpData['summary']['net_sales'])->toBe($restData['summary']['net_sales'])
                    ->and($mcpData['summary']['units_sold'])->toBe($restData['summary']['units_sold'])
                    ->and($mcpData['summary']['average_order_revenue'])->toBe($restData['summary']['average_order_revenue']);
            }
        );

        it(
            'guarantees cent-for-cent numeric parity on high refund products sorting', function (): void {
                // Query REST endpoint sorted by refund_rate
                $restResponse = s2sGet('/api/v1/products?from=2026-05-01&to=2026-05-31&sort=refund_rate&direction=desc', $this->shopA->shop_domain);
                $restResponse->assertStatus(200);
                $restItems = $restResponse->json('data');

                // Query MCP tool
                $mcpResponse = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-parity-ref',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
                            'arguments' => [
                                'from' => '2026-05-01',
                                'to' => '2026-05-31',
                                'sort_by' => 'refund_rate',
                                'direction' => 'desc',
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenA,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $mcpResponse->assertStatus(200);
                $mcpItems = json_decode($mcpResponse->json('result.content.0.text'), true)['items'];

                expect($mcpItems)->toHaveCount(1)
                    ->and($mcpItems[0]['id'])->toBe($restItems[0]['id'])
                    ->and($mcpItems[0]['title'])->toBe($restItems[0]['title'])
                    ->and($mcpItems[0]['gross_sales'])->toBe($restItems[0]['gross_sales'])
                    ->and($mcpItems[0]['refund_amount'])->toBe($restItems[0]['refunds'])
                    ->and($mcpItems[0]['net_sales'])->toBe($restItems[0]['net_sales']);
            }
        );

        it(
            'enforces platform neutrality with zero Shopify GIDs exposed in MCP responses', function (): void {
                $tools = [
                    'get_sync_status' => [],
                    'get_analytics_overview' => ['from' => '2026-05-01', 'to' => '2026-05-31'],
                    'list_product_performance' => ['from' => '2026-05-01', 'to' => '2026-05-31'],
                    'get_product_analytics' => ['product_id' => $this->productA, 'from' => '2026-05-01', 'to' => '2026-05-31'],
                    'get_sales_trend' => ['from' => '2026-05-01', 'to' => '2026-05-31'],
                    'find_high_refund_products' => ['from' => '2026-05-01', 'to' => '2026-05-31'],
                ];

                foreach ($tools as $toolName => $args) {
                    $response = $this->postJson(
                        '/mcp', [
                            'jsonrpc' => '2.0',
                            'id' => 'req-gid-check-'.$toolName,
                            'method' => 'tools/call',
                            'params' => [
                                'name' => $toolName,
                                'arguments' => $args,
                                '_meta' => [
                                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                                ],
                            ],
                        ], [
                            'Authorization' => 'Bearer '.$this->tokenA,
                            'MCP-Protocol-Version' => '2026-07-28',
                            'Mcp-Method' => 'tools/call',
                            'Mcp-Name' => $toolName,
                        ]
                    );

                    $response->assertStatus(200);
                    $rawText = $response->json('result.content.0.text');

                    // Strictly verify that NO Shopify GIDs leak to AI clients
                    expect($rawText)->not->toContain('gid://shopify/Product')
                        ->and($rawText)->not->toContain('gid://shopify/Shop')
                        ->and($rawText)->not->toContain('gid://shopify/LineItem')
                        ->and($rawText)->not->toContain('gid://shopify/ProductVariant')
                        ->and($rawText)->not->toContain('gid://shopify/Order');
                }
            }
        );

        it(
            'strictly isolates tenant data between concurrent shops', function (): void {
                // Query overview with Store A token
                $responseA = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-tenant-a',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => ['from' => '2026-05-01', 'to' => '2026-05-31'],
                            '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenA,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $dataA = json_decode($responseA->json('result.content.0.text'), true);

                // Query overview with Store B token
                $responseB = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-tenant-b',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => ['from' => '2026-05-01', 'to' => '2026-05-31'],
                            '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->tokenB,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/call',
                        'Mcp-Name' => 'get_analytics_overview',
                    ]
                );

                $dataB = json_decode($responseB->json('result.content.0.text'), true);

                // Assert Store A sees only its own metrics
                expect($dataA['summary']['gross_sales'])->toBe('1250.500000')
                    ->and($dataA['summary']['net_sales'])->toBe('1000.250000')
                    ->and($dataA['summary']['units_sold'])->toBe(25);

                // Assert Store B sees only its own metrics
                expect($dataB['summary']['gross_sales'])->toBe('4500.000000')
                    ->and($dataB['summary']['net_sales'])->toBe('3750.000000')
                    ->and($dataB['summary']['units_sold'])->toBe(45);
            }
        );
    }
);
