<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Support\McpTokenService;
use App\Mcp\Tools\FindHighRefundProductsTool;
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
                'shop_domain' => 'refund-tool.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/99301',
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
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/99301',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/931',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Refund Tool Test Client',
            staffRef: 'gid://shopify/Staff/931',
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
    'FindHighRefundProductsTool', function (): void {
        it(
            'has proper tool metadata and annotations', function (): void {
                $tool = new FindHighRefundProductsTool;

                expect($tool->name())->toBe('find_high_refund_products')
                    ->and($tool->description())->toContain('refund')
                    ->and($tool->annotations())->toHaveKeys(['readOnlyHint', 'idempotentHint', 'openWorldHint'])
                    ->and($tool->annotations()['readOnlyHint'])->toBeTrue()
                    ->and($tool->annotations()['idempotentHint'])->toBeTrue()
                    ->and($tool->annotations()['openWorldHint'])->toBeFalse();

                $refClass = new ReflectionClass(FindHighRefundProductsTool::class);
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
                        'id' => 'req-ref-err-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
                            'arguments' => [
                                'from' => 'invalid-date',
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('Invalid date format');
            }
        );

        it(
            'ranks products by refund rate descending with zero Shopify GIDs leaked', function (): void {
                $productA = (string) Str::uuid();
                $productB = (string) Str::uuid();
                $productC = (string) Str::uuid();

                DB::table('products')->insert(
                    [
                        [
                            'id' => $productA,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/99301',
                            'title' => 'Sneakers Model A',
                            'handle' => 'sneakers-model-a',
                            'vendor' => 'Stride Co',
                            'product_type' => 'Footwear',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productB,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/99302',
                            'title' => 'Boots Model B',
                            'handle' => 'boots-model-b',
                            'vendor' => 'Stride Co',
                            'product_type' => 'Footwear',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productC,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/99303',
                            'title' => 'Sandals Model C',
                            'handle' => 'sandals-model-c',
                            'vendor' => 'Stride Co',
                            'product_type' => 'Footwear',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                // Product A: Gross 1000, Refunds 200 => Refund Rate 20.00%
                // Product B: Gross 500, Refunds 250 => Refund Rate 50.00%
                // Product C: Gross 2000, Refunds 100 => Refund Rate 5.00%
                DB::table('product_daily_metrics')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productA,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '1000.000000',
                            'discounts' => '0.000000',
                            'refunds' => '200.000000',
                            'net_sales' => '800.000000',
                            'units_sold' => 8,
                            'order_count' => 5,
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
                            'gross_sales' => '500.000000',
                            'discounts' => '0.000000',
                            'refunds' => '250.000000',
                            'net_sales' => '250.000000',
                            'units_sold' => 2,
                            'order_count' => 3,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'product_id' => $productC,
                            'metric_date' => '2026-05-12',
                            'currency_code' => 'USD',
                            'gross_sales' => '2000.000000',
                            'discounts' => '0.000000',
                            'refunds' => '100.000000',
                            'net_sales' => '1900.000000',
                            'units_sold' => 19,
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
                        'records_received' => 20,
                        'records_processed' => 20,
                        'started_at' => now()->subHour(),
                        'completed_at' => now(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ref-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'sort_by' => 'refund_rate',
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                // Assert 3 products returned in order of refund rate: Product B (50%), Product A (20%), Product C (5%)
                expect($data['items'])->toHaveCount(3)
                    ->and($data['items'][0]['title'])->toBe('Boots Model B')
                    ->and($data['items'][0]['refund_rate'])->toEqual(50)
                    ->and($data['items'][1]['title'])->toBe('Sneakers Model A')
                    ->and($data['items'][1]['refund_rate'])->toEqual(20)
                    ->and($data['items'][2]['title'])->toBe('Sandals Model C')
                    ->and($data['items'][2]['refund_rate'])->toEqual(5);

                // Platform neutrality: zero Shopify GIDs in output
                $rawJson = json_encode($data);
                expect($rawJson)->not->toContain('gid://shopify/Product')
                    ->and($rawJson)->not->toContain('gid://shopify/Shop');

                // Metadata check
                expect($data['metadata'])->toHaveKey('currency_code', 'USD')
                    ->and($data['metadata'])->toHaveKey('plan', 'pro');
            }
        );

        it(
            'filters products by minimum refund rate threshold', function (): void {
                $productA = (string) Str::uuid();
                $productB = (string) Str::uuid();

                DB::table('products')->insert(
                    [
                        [
                            'id' => $productA,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/99311',
                            'title' => 'High Return Shirt',
                            'handle' => 'high-return-shirt',
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $productB,
                            'shop_id' => $this->shop->id,
                            'shopify_product_gid' => 'gid://shopify/Product/99312',
                            'title' => 'Low Return Pants',
                            'handle' => 'low-return-pants',
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
                            'gross_sales' => '1000.000000',
                            'discounts' => '0.000000',
                            'refunds' => '300.000000', // 30% refund rate
                            'net_sales' => '700.000000',
                            'units_sold' => 7,
                            'order_count' => 4,
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
                            'refunds' => '20.000000', // 2% refund rate
                            'net_sales' => '980.000000',
                            'units_sold' => 9,
                            'order_count' => 6,
                            'calculated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ref-filter',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'min_refund_rate' => 15.0, // Filters out Low Return Pants (2%)
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200);
                $data = json_decode($response->json('result.content.0.text'), true);

                expect($data['items'])->toHaveCount(1)
                    ->and($data['items'][0]['title'])->toBe('High Return Shirt');
            }
        );

        it(
            'strictly isolates high refund products across shops', function (): void {
                $otherShop = Shop::create(
                    [
                        'shop_domain' => 'other-refund-shop.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/99399',
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
                        'shopify_product_gid' => 'gid://shopify/Product/99399',
                        'title' => 'Other Shop Terrible Defect Coat',
                        'handle' => 'defect-coat',
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
                        'gross_sales' => '1000.000000',
                        'discounts' => '0.000000',
                        'refunds' => '999.000000',
                        'net_sales' => '1.000000',
                        'units_sold' => 1,
                        'order_count' => 1,
                        'calculated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ref-iso',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200);
                $data = json_decode($response->json('result.content.0.text'), true);

                $titles = collect($data['items'])->pluck('title')->all();
                expect($titles)->not->toContain('Other Shop Terrible Defect Coat');
            }
        );

        it(
            'rejects limit exceeding 50 with validation error rather than silently clamping', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ref-val-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
                            'arguments' => [
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'limit' => 500,
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('between 1 and 50');
            }
        );

        it(
            'rejects non-numeric, scientific notation, and space-padded min_refund_amount with validation error', function (): void {
                foreach (['abc', '1e1', ' 5', '-5'] as $badAmount) {
                    $response = $this->postJson(
                        '/mcp', [
                            'jsonrpc' => '2.0',
                            'id' => 'req-ref-val-2-'.$badAmount,
                            'method' => 'tools/call',
                            'params' => [
                                'name' => 'find_high_refund_products',
                                'arguments' => [
                                    'from' => '2026-05-11',
                                    'to' => '2026-05-20',
                                    'min_refund_amount' => $badAmount,
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
                            'Mcp-Name' => 'find_high_refund_products',
                        ]
                    );

                    $response->assertStatus(200)
                        ->assertJsonPath('result.isError', true);
                    expect($response->json('result.content.0.text'))->toContain('valid non-negative number');
                }
            }
        );

        it(
            'rejects invalid sort_by and impossible date 2026-02-30', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ref-val-3',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
                            'arguments' => [
                                'from' => '2026-02-30',
                                'to' => '2026-05-20',
                                'sort_by' => 'bogus; drop table x',
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                $text = $response->json('result.content.0.text');
                expect($text)->toContain('Invalid date format')
                    ->and($text)->toContain('selected sort by is invalid');
            }
        );

        it(
            'rejects future date range with explicit error message', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-ref-val-4',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'find_high_refund_products',
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
                        'Mcp-Name' => 'find_high_refund_products',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('includes future dates');
            }
        );
    }
);
