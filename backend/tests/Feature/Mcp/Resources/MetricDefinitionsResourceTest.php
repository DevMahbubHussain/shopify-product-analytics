<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Mcp\Resources\MetricDefinitionsResource;
use App\Mcp\Support\McpTokenService;
use App\Models\McpConsent;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'docs-resource-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77021',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now()->subDays(15),
            ]
        );

        DB::table('subscriptions')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77021',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/721',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Docs Resource Client',
            staffRef: 'gid://shopify/Staff/721',
        );
    }
);

describe(
    'MetricDefinitionsResource', function (): void {
        it(
            'has proper resource metadata and uri attributes', function (): void {
                $resource = new MetricDefinitionsResource;

                expect($resource->name())->toBe('metric_definitions')
                    ->and($resource->title())->toBe('Metric Definitions')
                    ->and($resource->uri())->toBe('analytics://docs/metric-definitions')
                    ->and($resource->mimeType())->toBe('text/markdown')
                    ->and($resource->description())->toContain('reference document');
            }
        );

        it(
            'lists the documentation resource via resources/list JSON-RPC method', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'doc-res-list-1',
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
                expect($uris)->toContain('analytics://docs/metric-definitions');
            }
        );

        it(
            'reads metric definitions markdown document via resources/read', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'doc-res-read-1',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://docs/metric-definitions',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://docs/metric-definitions',
                    ]
                );

                $response->assertStatus(200);

                $content = $response->json('result.contents.0');
                expect($content['uri'])->toBe('analytics://docs/metric-definitions')
                    ->and($content['mimeType'])->toBe('text/markdown');

                $text = $content['text'];

                // Verify grounding sections for financial analytics
                expect($text)->toContain('Gross Sales')
                    ->and($text)->toContain('Net Sales')
                    ->and($text)->toContain('Discounts')
                    ->and($text)->toContain('Refunds')
                    ->and($text)->toContain('Units Sold')
                    ->and($text)->toContain('Refund Rate')
                    ->and($text)->toContain('Average Order Revenue')
                    ->and($text)->toContain('Currency Isolation')
                    ->and($text)->toContain('Half-Open Day Boundaries');

                // Zero platform GIDs
                expect($text)->not->toContain('gid://shopify');
            }
        );

        it(
            'blocks free tier shops from accessing metric definitions resource', function (): void {
                DB::table('subscriptions')
                    ->where('shop_id', $this->shop->id)
                    ->update(['plan_key' => 'free']);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'doc-res-read-free',
                        'method' => 'resources/read',
                        'params' => [
                            'uri' => 'analytics://docs/metric-definitions',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'resources/read',
                        'Mcp-Name' => 'analytics://docs/metric-definitions',
                    ]
                );

                $response->assertStatus(403)
                    ->assertJsonPath('error', 'entitlement_denied');
            }
        );
    }
);

it('documents the actual implementation without phantom parameters', function (): void {
    $text = (string) app(\App\Mcp\Resources\MetricDefinitionsResource::class)
        ->handle(new \Laravel\Mcp\Request([]))
        ->content();

    expect($text)->toContain('Monday')
        ->and($text)->not->toContain('When `compare_previous: true` is requested')
        ->and($text)->toContain('`get_analytics_overview` always includes a preceding-period comparison')
        ->and($text)->not->toContain('anomalous refund state')
        ->and($text)->toContain('excluded from `find_high_refund_products`');
});
