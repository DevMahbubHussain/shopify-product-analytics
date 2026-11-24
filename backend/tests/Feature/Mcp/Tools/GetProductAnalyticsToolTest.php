<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Support\McpTokenService;
use App\Mcp\Tools\GetProductAnalyticsTool;
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
                'shop_domain' => 'detail-tool.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/99101',
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
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/99101',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/911',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Detail Tool Test Client',
            staffRef: 'gid://shopify/Staff/911',
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
    'GetProductAnalyticsTool', function (): void {
        it(
            'has proper tool metadata and annotations', function (): void {
                $tool = new GetProductAnalyticsTool;

                expect($tool->name())->toBe('get_product_analytics')
                    ->and($tool->description())->toContain('product')
                    ->and($tool->annotations())->toHaveKeys(['readOnlyHint', 'idempotentHint', 'openWorldHint'])
                    ->and($tool->annotations()['readOnlyHint'])->toBeTrue()
                    ->and($tool->annotations()['idempotentHint'])->toBeTrue()
                    ->and($tool->annotations()['openWorldHint'])->toBeFalse();

                $refClass = new ReflectionClass(GetProductAnalyticsTool::class);
                expect($refClass->getAttributes(IsReadOnly::class))->not->toBeEmpty()
                    ->and($refClass->getAttributes(IsIdempotent::class))->not->toBeEmpty()
                    ->and($refClass->getAttributes(IsOpenWorld::class))->not->toBeEmpty();
            }
        );

        it(
            'rejects missing or invalid arguments with helpful errors', function (): void {
                // Missing product_id
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-err-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('product_id');

                // Non-existent product_id
                $response2 = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-err-2',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'product_id' => (string) Str::uuid(),
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response2->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response2->json('result.content.0.text'))->toContain('Product not found');
            }
        );

        it(
            'returns detailed product analytics and variant breakdown with platform neutrality', function (): void {
                $productId = (string) Str::uuid();
                $variant1Id = (string) Str::uuid();
                $variant2Id = (string) Str::uuid();

                DB::table('products')->insert(
                    [
                        'id' => $productId,
                        'shop_id' => $this->shop->id,
                        'shopify_product_gid' => 'gid://shopify/Product/99101',
                        'title' => 'Merino Wool Sweater',
                        'handle' => 'merino-wool-sweater',
                        'vendor' => 'Nordic Knitwear',
                        'product_type' => 'Knitwear',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                DB::table('product_variants')->insert(
                    [
                        [
                            'id' => $variant1Id,
                            'shop_id' => $this->shop->id,
                            'product_id' => $productId,
                            'shopify_variant_gid' => 'gid://shopify/ProductVariant/901',
                            'title' => 'Navy / Medium',
                            'sku' => 'NORD-SWEAT-NV-M',
                            'price' => '120.000000',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $variant2Id,
                            'shop_id' => $this->shop->id,
                            'product_id' => $productId,
                            'shopify_variant_gid' => 'gid://shopify/ProductVariant/902',
                            'title' => 'Oatmeal / Large',
                            'sku' => 'NORD-SWEAT-OAT-L',
                            'price' => '120.000000',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                // Product daily metrics
                DB::table('product_daily_metrics')->insert(
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $this->shop->id,
                        'product_id' => $productId,
                        'metric_date' => '2026-05-12',
                        'currency_code' => 'USD',
                        'gross_sales' => '360.000000',
                        'discounts' => '20.000000',
                        'refunds' => '0.000000',
                        'net_sales' => '340.000000',
                        'units_sold' => 3,
                        'order_count' => 2,
                        'calculated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Orders and line items for variant level breakdown
                $order1Id = (string) Str::uuid();
                $order2Id = (string) Str::uuid();

                DB::table('orders')->insert(
                    [
                        [
                            'id' => $order1Id,
                            'shop_id' => $this->shop->id,
                            'shopify_order_gid' => 'gid://shopify/Order/991',
                            'order_name' => '#1091',
                            'financial_status' => 'paid',
                            'currency_code' => 'USD',
                            'processed_at' => '2026-05-12 14:00:00+00',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => $order2Id,
                            'shop_id' => $this->shop->id,
                            'shopify_order_gid' => 'gid://shopify/Order/992',
                            'order_name' => '#1092',
                            'financial_status' => 'paid',
                            'currency_code' => 'USD',
                            'processed_at' => '2026-05-12 16:00:00+00',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]
                );

                DB::table('order_line_items')->insert(
                    [
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'order_id' => $order1Id,
                            'product_id' => $productId,
                            'variant_id' => $variant1Id,
                            'shopify_line_item_gid' => 'gid://shopify/LineItem/991',
                            'title_snapshot' => 'Merino Wool Sweater',
                            'sku_snapshot' => 'NORD-SWEAT-NV-M',
                            'quantity' => 2,
                            'original_unit_price' => '120.000000',
                            'discount_amount' => '10.000000',
                            'currency_code' => 'USD',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $this->shop->id,
                            'order_id' => $order2Id,
                            'product_id' => $productId,
                            'variant_id' => $variant2Id,
                            'shopify_line_item_gid' => 'gid://shopify/LineItem/992',
                            'title_snapshot' => 'Merino Wool Sweater',
                            'sku_snapshot' => 'NORD-SWEAT-OAT-L',
                            'quantity' => 1,
                            'original_unit_price' => '120.000000',
                            'discount_amount' => '10.000000',
                            'currency_code' => 'USD',
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
                        'records_received' => 30,
                        'records_processed' => 30,
                        'started_at' => now()->subHours(2),
                        'completed_at' => now()->subHour(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'product_id' => $productId,
                                'from' => '2026-05-11',
                                'to' => '2026-05-20',
                                'include_variants' => true,
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);

                $text = $response->json('result.content.0.text');
                $data = json_decode($text, true);

                // Product details check
                expect($data['product'])->toHaveKey('id', $productId)
                    ->and($data['product'])->toHaveKey('title', 'Merino Wool Sweater')
                    ->and($data['product'])->toHaveKey('vendor', 'Nordic Knitwear')
                    ->and($data['summary'])->toHaveKey('gross_sales', '360.000000')
                    ->and($data['summary'])->toHaveKey('net_sales', '340.000000')
                    ->and($data['summary'])->toHaveKey('units_sold', 3);

                // Variants breakdown check
                expect($data['variants'])->toHaveCount(2)
                    ->and($data['variants'][0]['title'])->toBe('Navy / Medium')
                    ->and($data['variants'][0]['units_sold'])->toBe(2)
                    ->and($data['variants'][1]['title'])->toBe('Oatmeal / Large')
                    ->and($data['variants'][1]['units_sold'])->toBe(1);

                // Platform Neutrality check: zero Shopify GIDs in entire output
                $rawJson = json_encode($data);
                expect($rawJson)->not->toContain('gid://shopify/Product')
                    ->and($rawJson)->not->toContain('gid://shopify/ProductVariant')
                    ->and($rawJson)->not->toContain('gid://shopify/LineItem');

                // Metadata check
                expect($data['metadata'])->toHaveKey('currency_code', 'USD')
                    ->and($data['metadata'])->toHaveKey('timezone', 'America/New_York')
                    ->and($data['metadata'])->toHaveKey('plan', 'pro');
            }
        );

        it(
            'strictly isolates products across shops', function (): void {
                $otherShop = Shop::create(
                    [
                        'shop_domain' => 'other-det-shop.myshopify.com',
                        'shopify_shop_gid' => 'gid://shopify/Shop/99109',
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
                        'shopify_product_gid' => 'gid://shopify/Product/99199',
                        'title' => 'Other Shop Secret Sweater',
                        'handle' => 'other-secret-sweater',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-iso',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'product_id' => $otherProductId,
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toContain('Product not found in this store.');
            }
        );

        it(
            'supports product lookup by handle and returns identical metrics', function (): void {
                $productId = (string) Str::uuid();
                DB::table('products')->insert([
                    'id' => $productId,
                    'shop_id' => $this->shop->id,
                    'shopify_product_gid' => 'gid://shopify/Product/99501',
                    'title' => 'Silk Necktie',
                    'handle' => 'silk-necktie',
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
                    'gross_sales' => '350.000000',
                    'discounts' => '50.000000',
                    'refunds' => '0.000000',
                    'net_sales' => '300.000000',
                    'units_sold' => 7,
                    'order_count' => 4,
                    'calculated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-handle-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'handle' => 'silk-necktie',
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', false);
                $data = json_decode($response->json('result.content.0.text'), true);

                expect($data['product']['id'])->toBe($productId)
                    ->and($data['product']['handle'])->toBe('silk-necktie')
                    ->and($data['summary']['net_sales'])->toBe('300.000000')
                    ->and($data['daily_trend'])->toBeArray()
                    ->and($data['daily_trend'])->toHaveCount(10);
            }
        );

        it(
            'rejects invalid UUIDs and conflicting identifier arguments with validation errors', function (): void {
                // Invalid UUID
                $response1 = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-val-1',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'product_id' => 'not-a-uuid',
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response1->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response1->json('result.content.0.text'))->toContain('must be a valid UUID');

                // Both product_id and handle
                $response2 = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-val-2',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'product_id' => (string) Str::uuid(),
                                'handle' => 'some-handle',
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response2->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response2->json('result.content.0.text'))->toContain('Cannot provide both');
            }
        );

        it(
            'returns identical error message when handle is not found', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'req-det-val-3',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_product_analytics',
                            'arguments' => [
                                'handle' => 'non-existent-handle-xyz',
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
                        'Mcp-Name' => 'get_product_analytics',
                    ]
                );

                $response->assertStatus(200)
                    ->assertJsonPath('result.isError', true);
                expect($response->json('result.content.0.text'))->toBe('Product not found in this store.');
            }
        );
    }
);
