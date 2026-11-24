<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Mcp\Support\McpTokenService;
use App\Mcp\Tools\GetSalesTrendTool;
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

beforeEach(function (): void {
    $this->shop = Shop::create([
        'shop_domain' => 'trend-tool.myshopify.com',
        'shopify_shop_gid' => 'gid://shopify/Shop/99201',
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
    ]);

    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $this->shop->id,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/99201',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->consent = McpConsent::create([
        'shop_id' => $this->shop->id,
        'platform' => Platform::Shopify,
        'granted_by_staff_ref' => 'gid://shopify/Staff/921',
        'policy_version' => 'v1.0',
        'granted_at' => now(),
    ]);

    $this->tokenService = app(McpTokenService::class);
    $this->generatedToken = $this->tokenService->createToken(
        shop: $this->shop,
        name: 'Trend Tool Test Client',
        staffRef: 'gid://shopify/Staff/921',
    );

    SyncRun::create([
        'shop_id' => $this->shop->id,
        'sync_type' => SyncType::Initial,
        'status' => SyncRunStatus::Completed,
        'records_received' => 50,
        'records_processed' => 50,
        'cursor_metadata' => ['window_start' => '2026-01-01T00:00:00Z'],
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHour(),
    ]);
});

describe('GetSalesTrendTool', function (): void {
    it('has proper tool metadata and annotations', function (): void {
        $tool = new GetSalesTrendTool;

        expect($tool->name())->toBe('get_sales_trend')
            ->and($tool->description())->toContain('trend')
            ->and($tool->annotations())->toHaveKeys(['readOnlyHint', 'idempotentHint', 'openWorldHint'])
            ->and($tool->annotations()['readOnlyHint'])->toBeTrue()
            ->and($tool->annotations()['idempotentHint'])->toBeTrue()
            ->and($tool->annotations()['openWorldHint'])->toBeFalse();

        $refClass = new ReflectionClass(GetSalesTrendTool::class);
        expect($refClass->getAttributes(IsReadOnly::class))->not->toBeEmpty()
            ->and($refClass->getAttributes(IsIdempotent::class))->not->toBeEmpty()
            ->and($refClass->getAttributes(IsOpenWorld::class))->not->toBeEmpty();
    });

    it('rejects invalid date formats with actionable guidance', function (): void {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'req-tr-err-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_sales_trend',
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
            'Mcp-Name' => 'get_sales_trend',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('result.isError', true);
        expect($response->json('result.content.0.text'))->toContain('Invalid date format');
    });

    it('returns continuous daily time-series points with zero-filled gaps', function (): void {
        $productId = (string) Str::uuid();

        DB::table('products')->insert([
            'id' => $productId,
            'shop_id' => $this->shop->id,
            'shopify_product_gid' => 'gid://shopify/Product/99201',
            'title' => 'Canvas Backpack',
            'handle' => 'canvas-backpack',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Daily metrics on Day 1 (2026-05-10) and Day 3 (2026-05-12); Day 2 (2026-05-11) is empty
        DB::table('product_daily_metrics')->insert([
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'product_id' => $productId,
                'metric_date' => '2026-05-10',
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
                'metric_date' => '2026-05-12',
                'currency_code' => 'USD',
                'gross_sales' => '200.000000',
                'discounts' => '20.000000',
                'refunds' => '0.000000',
                'net_sales' => '180.000000',
                'units_sold' => 4,
                'order_count' => 2,
                'calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        SyncRun::create([
            'shop_id' => $this->shop->id,
            'sync_type' => SyncType::Initial,
            'status' => SyncRunStatus::Completed,
            'records_received' => 10,
            'records_processed' => 10,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'req-tr-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_sales_trend',
                'arguments' => [
                    'from' => '2026-05-10',
                    'to' => '2026-05-12',
                    'interval' => 'day',
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
            'Mcp-Name' => 'get_sales_trend',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('result.isError', false);

        $text = $response->json('result.content.0.text');
        $data = json_decode($text, true);

        expect($data['interval'])->toBe('day')
            ->and($data['currency'])->toBe('USD')
            ->and($data['points'])->toHaveCount(3);

        // Day 1
        expect($data['points'][0]['date'])->toBe('2026-05-10')
            ->and($data['points'][0]['net_sales'])->toBe('90.000000')
            ->and($data['points'][0]['units_sold'])->toBe(2);

        // Day 2 (zero-filled gap)
        expect($data['points'][1]['date'])->toBe('2026-05-11')
            ->and($data['points'][1]['gross_sales'])->toBe('0.000000')
            ->and($data['points'][1]['net_sales'])->toBe('0.000000')
            ->and($data['points'][1]['units_sold'])->toBe(0);

        // Day 3
        expect($data['points'][2]['date'])->toBe('2026-05-12')
            ->and($data['points'][2]['net_sales'])->toBe('180.000000')
            ->and($data['points'][2]['units_sold'])->toBe(4);

        // Metadata check
        expect($data['metadata'])->toHaveKey('currency_code', 'USD')
            ->and($data['metadata'])->toHaveKey('plan', 'pro');
    });

    it('supports weekly and monthly grouping intervals', function (): void {
        $productId = (string) Str::uuid();

        DB::table('products')->insert([
            'id' => $productId,
            'shop_id' => $this->shop->id,
            'shopify_product_gid' => 'gid://shopify/Product/99202',
            'title' => 'Duffel Bag',
            'handle' => 'duffel-bag',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shop->id,
            'product_id' => $productId,
            'metric_date' => '2026-05-10',
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

        // Test week interval
        $responseWeek = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'req-tr-wk',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_sales_trend',
                'arguments' => [
                    'from' => '2026-05-01',
                    'to' => '2026-05-31',
                    'interval' => 'week',
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
            'Mcp-Name' => 'get_sales_trend',
        ]);

        $responseWeek->assertStatus(200);
        $dataWeek = json_decode($responseWeek->json('result.content.0.text'), true);
        expect($dataWeek['interval'])->toBe('week')
            ->and($dataWeek['points'])->not->toBeEmpty();

        // Test month interval
        $responseMonth = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'req-tr-mo',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_sales_trend',
                'arguments' => [
                    'from' => '2026-01-01',
                    'to' => '2026-05-31',
                    'interval' => 'month',
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
            'Mcp-Name' => 'get_sales_trend',
        ]);

        $responseMonth->assertStatus(200);
        $dataMonth = json_decode($responseMonth->json('result.content.0.text'), true);
        expect($dataMonth['interval'])->toBe('month')
            ->and($dataMonth['points'])->toHaveCount(5);
    });

    it('strictly isolates trend metrics across shops', function (): void {
        $otherShop = Shop::create([
            'shop_domain' => 'other-trend-shop.myshopify.com',
            'shopify_shop_gid' => 'gid://shopify/Shop/99209',
            'currency_code' => 'USD',
            'timezone' => 'America/New_York',
            'status' => 'active',
            'installed_at' => now(),
        ]);

        $otherProductId = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $otherProductId,
            'shop_id' => $otherShop->id,
            'shopify_product_gid' => 'gid://shopify/Product/99299',
            'title' => 'Other Trend Bag',
            'handle' => 'other-trend-bag',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => $otherShop->id,
            'product_id' => $otherProductId,
            'metric_date' => '2026-05-10',
            'currency_code' => 'USD',
            'gross_sales' => '99999.000000',
            'discounts' => '0.000000',
            'refunds' => '0.000000',
            'net_sales' => '99999.000000',
            'units_sold' => 999,
            'order_count' => 99,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'req-tr-iso',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_sales_trend',
                'arguments' => [
                    'from' => '2026-05-10',
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
            'Mcp-Name' => 'get_sales_trend',
        ]);

        $response->assertStatus(200);
        $data = json_decode($response->json('result.content.0.text'), true);

        // Our shop has no data on 2026-05-10, so it must be zero
        expect($data['points'][0]['net_sales'])->toBe('0.000000')
            ->and($data['points'][0]['units_sold'])->toBe(0);
    });

    it('rejects invalid interval and impossible dates with validation error', function (): void {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'req-tr-val-1',
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_sales_trend',
                'arguments' => [
                    'from' => '2026-02-30',
                    'to' => '2026-03-10',
                    'interval' => 'yearly',
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
            'Mcp-Name' => 'get_sales_trend',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('result.isError', true);
        $text = $response->json('result.content.0.text');
        expect($text)->toContain('Invalid date format')
            ->and($text)->toContain('selected interval is invalid');
    });
});
