<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Support\McpTokenService;
use App\Mcp\Tools\ListProductPerformanceTool;
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
                'shop_domain' => 'perf-tool.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/99001',
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
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/99001',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/901',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Perf Tool Test Client',
            staffRef: 'gid://shopify/Staff/901',
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
    'ListProductPerformanceTool', function (): void {
        it(
            'has proper tool metadata and annotations', function (): void {
                $tool = new ListProductPerformanceTool;

                expect($tool->name())->toBe('list_product_performance')
                    ->and($tool->description())->toContain('performance')
                    ->and($tool->annotations())->toHaveKeys(['readOnlyHint', 'idempotentHint', 'openWorldHint'])
                    ->and($tool->annotations()['readOnlyHint'])->toBeTrue()
                    ->and($tool->annotations()['idempotentHint'])->toBeTrue()
                    ->and($tool->annotations()['openWorldHint'])->toBeFalse();

                $refClass = new ReflectionClass(ListProductPerformanceTool::class);
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
                        'id' => 'req-perf-err-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => 'invalid-date',
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
                        'Mcp-Name' => 'list_product_performance',
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
            'lists products sorted by net sales descending with zero shopify GIDs leaked', function (): void {
                $productA = (string) Str::uuid();
                $productB = (string) Str::uuid();
                $productC = (string) Str::uuid();

                DB::table('products')->insert(
                    [
                        [
                            'id' => $productA,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/101',
                            'title' => 'Alpha Jacket',
                            'handle' => 'alpha-jacket',
                            'vendor' => 'Acme Apparel',
                            'product_type' => 'Outerwear',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productB,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/102',
                            'title' => 'Beta Beanie',
                            'handle' => 'beta-beanie',
                            'vendor' => 'Acme Apparel',
                            'product_type' => 'Accessories',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productC,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/103',
                            'title' => 'Gamma Coat',
                            'handle' => 'gamma-coat',
                            'vendor' => 'Peak Gear',
                            'product_type' => 'Outerwear',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                DB::table('product_daily_metrics')->insert(
                    [
                        // Product A: Net $450, Units 10
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productA,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '500.000000',
                            'discounts' => '50.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '450.000000',
                            'units_sold' => 10,
                            'order_count' => 5,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        // Product B: Net $180, Units 5
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productB,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '200.000000',
                            'discounts' => '20.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '180.000000',
                            'units_sold' => 5,
                            'order_count' => 3,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        // Product C: Net $900, Units 20
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productC,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '1000.000000',
                            'discounts' => '100.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '900.000000',
                            'units_sold' => 20,
                            'order_count' => 10,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                SyncRun::create(
                    [
                        'shop_id' => $this->shop->id,
                        'sync_type' => SyncType::Initial,
                        'status' => SyncRunStatus::Completed,
                        'records_received' => 50,
                        'records_processed' => 50,
                        'started_at' => now()->subHour(),
                        'completed_at' => now(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'sort' => 'net_sales',
                                'direction' => 'desc',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                // Assert 3 items returned in order: Gamma Coat ($900), Alpha Jacket ($450), Beta Beanie ($180)
                expect($data['items'])->toHaveCount(3)
                    ->and($data['items'][0]['title'])->toBe('Gamma Coat')
                    ->and($data['items'][0]['net_sales'])->toBe('900.000000')
                    ->and($data['items'][1]['title'])->toBe('Alpha Jacket')
                    ->and($data['items'][1]['net_sales'])->toBe('450.000000')
                    ->and($data['items'][2]['title'])->toBe('Beta Beanie')
                    ->and($data['items'][2]['net_sales'])->toBe('180.000000');

                // Platform Neutrality Assertion: zero raw Shopify GIDs anywhere in output
                $rawJson = json_encode($data);
                expect($rawJson)->not->toContain('gid://shopify/Product')
                    ->and($rawJson)->not->toContain('gid://shopify/Shop');

                // Pagination and metadata checks
                expect($data['pagination'])->toHaveKey('total', 3)
                    ->and($data['pagination'])->toHaveKey('current_page', 1)
                    ->and($data['metadata'])->toHaveKey('currency_code', 'USD')
                    ->and($data['metadata'])->toHaveKey('plan', 'pro');
            }
        );

        it(
            'supports sorting by units_sold ascending', function (): void {
                $productA = (string) Str::uuid();
                $productB = (string) Str::uuid();

                DB::table('products')->insert(
                    [
                        [
                            'id' => $productA,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/201',
                            'title' => 'High Volume Socks',
                            'handle' => 'high-volume-socks',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productB,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/202',
                            'title' => 'Low Volume Watch',
                            'handle' => 'low-volume-watch',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                DB::table('product_daily_metrics')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productA,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '500.000000',
                            'discounts' => '0.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '500.000000',
                            'units_sold' => 50,
                            'order_count' => 10,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productB,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '1000.000000',
                            'discounts' => '0.000000',
                            'refunds' => '0.000000',
                            'net_sales' => '1000.000000',
                            'units_sold' => 2,
                            'order_count' => 2,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-sort',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'sort' => 'units_sold',
                                'direction' => 'asc',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $response->assertStatus(200);
                $data = json_decode($response->json('result.content.0.text'), true);

                expect($data['items'][0]['title'])->toBe('Low Volume Watch')
                    ->and($data['items'][0]['units_sold'])->toBe(2)
                    ->and($data['items'][1]['title'])->toBe('High Volume Socks')
                    ->and($data['items'][1]['units_sold'])->toBe(50);
            }
        );

        it(
            'filters products by search title keyword', function (): void {
                $productA = (string) Str::uuid();
                $productB = (string) Str::uuid();

                DB::table('products')->insert(
                    [
                        [
                            'id' => $productA,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/301',
                            'title' => 'Silk Scarf Special',
                            'handle' => 'silk-scarf-special',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productB,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/302',
                            'title' => 'Wool Beanie',
                            'handle' => 'wool-beanie',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-search',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'search' => 'Silk',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $response->assertStatus(200);
                $data = json_decode($response->json('result.content.0.text'), true);

                expect($data['items'])->toHaveCount(1)
                    ->and($data['items'][0]['title'])->toBe('Silk Scarf Special');
            }
        );

        it(
            'strictly isolates products across shops', function (): void {
                $otherShop = Shop::create(
                    [
                        'shop_domain' => 'other-perf-shop.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/99009',
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
                        'shopify_product_gid' => 'gid://shopify/Product/9999',
                        'title' => 'Secret Item For Other Store',
                        'handle' => 'secret-item',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-iso',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $response->assertStatus(200);
                $data = json_decode($response->json('result.content.0.text'), true);

                $titles = collect($data['items'])->pluck('title')->all();
                expect($titles)->not->toContain('Secret Item For Other Store');
            }
        );

        it(
            'rejects limit exceeding 50 with validation error rather than silently clamping', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-val-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'limit' => 100,
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('between 1 and 50');
            }
        );

        it(
            'rejects invalid sort field bogus with validation error', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-val-2',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'sort' => 'bogus; drop table x',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('selected sort is invalid');
            }
        );

        it(
            'supports sorting by refund_rate and escapes wildcards in search query', function (): void {
                $p1 = (string) Str::uuid();
                $p2 = (string) Str::uuid();

                DB::table('products')->insert([
                    [
                        'id' => $p1,
                        'shop_id' => $this->shop->id,
                        'shopify_product_gid' => 'gid://shopify/Product/99881',
                        'title' => '100% Wool Sweater',
                        'handle' => 'wool-sweater',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'id' => $p2,
                        'shop_id' => $this->shop->id,
                        'shopify_product_gid' => 'gid://shopify/Product/99882',
                        'title' => 'Cotton T-Shirt',
                        'handle' => 'cotton-tshirt',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);

                DB::table('product_daily_metrics')->insert([
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $this->shop->id,
                        'product_id' => $p1,
                        'metric_date' => '2026-05-15',
                        'currency_code' => 'USD',
                        'gross_sales' => '500.000000',
                        'discounts' => '0.000000',
                        'refunds' => '250.000000', // 50% refund rate
                        'net_sales' => '250.000000',
                        'units_sold' => 5,
                        'order_count' => 5,
                        'calculated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $this->shop->id,
                        'product_id' => $p2,
                        'metric_date' => '2026-05-15',
                        'currency_code' => 'USD',
                        'gross_sales' => '1000.000000',
                        'discounts' => '0.000000',
                        'refunds' => '100.000000', // 10% refund rate
                        'net_sales' => '900.000000',
                        'units_sold' => 10,
                        'order_count' => 10,
                        'calculated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);

                // Sort by refund_rate desc
                $responseSort = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-val-3',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'sort' => 'refund_rate',
                                'direction' => 'desc',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $responseSort->assertStatus(200)
                    ->assertJsonPath('result.isError', false);
                $items = json_decode($responseSort->json('result.content.0.text'), true)['items'];
                expect($items[0]['title'])->toBe('100% Wool Sweater');

                // Search literal 100% (escaped %)
                $responseSearch = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-perf-val-4',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'list_product_performance',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'search' => '100%',
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
                        'Mcp-Name' => 'list_product_performance',
                    ]
                );

                $responseSearch->assertStatus(200)
                    ->assertJsonPath('result.isError', false);
                $searchItems = json_decode($responseSearch->json('result.content.0.text'), true)['items'];
                expect($searchItems)->toHaveCount(1)
                    ->and($searchItems[0]['title'])->toBe('100% Wool Sweater');
            }
        );
    }
);
