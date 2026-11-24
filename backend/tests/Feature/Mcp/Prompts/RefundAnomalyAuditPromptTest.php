<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Mcp\Prompts\RefundAnomalyAuditPrompt;
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
                'shop_domain' => 'prompt-refund-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77041',
                'currency_code' => 'USD',
                'timezone' => 'America/Chicago',
                'status' => 'active',
                'installed_at' => now()->subDays(60),
            ]
        );

        DB::table('subscriptions')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77041',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/741',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Prompt Refund Client',
            staffRef: 'gid://shopify/Staff/741',
        );
    }
);

describe(
    'RefundAnomalyAuditPrompt', function (): void {
        it(
            'has proper prompt metadata and arguments', function (): void {
                $prompt = new RefundAnomalyAuditPrompt;

                expect($prompt->name())->toBe('refund_anomaly_audit')
                    ->and($prompt->title())->toBe('Refund Anomaly Audit')
                    ->and($prompt->description())->toContain('refund anomalies');

                $arguments = $prompt->arguments();
                expect($arguments)->toHaveCount(2)
                    ->and($arguments[0]->name)->toBe('days')
                    ->and($arguments[0]->required)->toBeFalse()
                    ->and($arguments[1]->name)->toBe('min_refund_rate_pct')
                    ->and($arguments[1]->required)->toBeFalse();
            }
        );

        it(
            'lists the prompt via prompts/list JSON-RPC method', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-list-ref',
                        'method' => 'prompts/list',
                        'params' => [
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'prompts/list',
                    ]
                );

                $response->assertStatus(200);
                $names = collect($response->json('result.prompts'))->pluck('name')->all();
                expect($names)->toContain('refund_anomaly_audit');

                $audit = collect($response->json('result.prompts'))->firstWhere('name', 'refund_anomaly_audit');
                expect($audit['arguments'])->toHaveCount(2);
            }
        );

        it(
            'generates a refund anomaly audit prompt via prompts/get with custom arguments', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-get-ref-1',
                        'method' => 'prompts/get',
                        'params' => [
                            'name' => 'refund_anomaly_audit',
                            'arguments' => [
                                'days' => 14,
                                'min_refund_rate_pct' => 10,
                            ],
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'prompts/get',
                        'Mcp-Name' => 'refund_anomaly_audit',
                    ]
                );

                $response->assertStatus(200);

                $result = $response->json('result');
                expect($result['description'])->toContain('refund anomalies');

                $message = $result['messages'][0];
                expect($message['role'])->toBe('user');

                $promptText = $message['content']['text'];

                // Verify instructions and tool workflow references
                expect($promptText)->toContain('find_high_refund_products')
                    ->and($promptText)->toContain('get_product_analytics')
                    ->and($promptText)->toContain('10')
                    ->and($promptText)->toContain('14');

                // Zero platform GIDs
                expect($promptText)->not->toContain('gid://shopify');
            }
        );

        it(
            'defaults to 30 days and 5 percent threshold when arguments are omitted', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-get-ref-def',
                        'method' => 'prompts/get',
                        'params' => [
                            'name' => 'refund_anomaly_audit',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'prompts/get',
                        'Mcp-Name' => 'refund_anomaly_audit',
                    ]
                );

                $response->assertStatus(200);
                $promptText = $response->json('result.messages.0.content.text');

                expect($promptText)->toContain('30')
                    ->and($promptText)->toContain('5');
            }
        );

        it(
            'blocks free tier shops from accessing refund audit prompt', function (): void {
                DB::table('subscriptions')
                    ->where('shop_id', $this->shop->id)
                    ->update(['plan_key' => 'free']);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-get-ref-free',
                        'method' => 'prompts/get',
                        'params' => [
                            'name' => 'refund_anomaly_audit',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'prompts/get',
                        'Mcp-Name' => 'refund_anomaly_audit',
                    ]
                );

                $response->assertStatus(403)
                    ->assertJsonPath('error', 'entitlement_denied');
            }
        );
    }
);

/** @param array<string, mixed> $arguments */
function refundAuditPromptGet(string $token, array $arguments): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'prompt-edge',
        'method' => 'prompts/get',
        'params' => [
            'name' => 'refund_anomaly_audit',
            'arguments' => (object) $arguments,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ],
        ],
    ], [
        'Authorization' => 'Bearer '.$token,
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'prompts/get',
        'Mcp-Name' => 'refund_anomaly_audit',
    ]);
}

describe('RefundAnomalyAuditPrompt hardening', function (): void {
    it('never embeds the platform shop domain in the prompt text', function (): void {
        $text = refundAuditPromptGet($this->generatedToken->plaintextToken, [])
            ->assertOk()->json('result.messages.0.content.text');

        expect($text)->not->toContain('prompt-refund-store')
            ->and(strtolower($text))->not->toContain('shopify');
    });

    it('rejects days outside 1..365 or non-integer days', function (mixed $days): void {
        refundAuditPromptGet($this->generatedToken->plaintextToken, ['days' => $days])
            ->assertJsonPath('error.code', -32602);
    })->with(['too many' => [9999], 'zero' => [0], 'text' => ['abc']]);

    it('rejects min_refund_rate_pct outside 0..100', function (mixed $rate): void {
        refundAuditPromptGet($this->generatedToken->plaintextToken, ['min_refund_rate_pct' => $rate])
            ->assertJsonPath('error.code', -32602);
    })->with(['negative' => [-1], 'over 100' => [150], 'text' => ['abc']]);
});
