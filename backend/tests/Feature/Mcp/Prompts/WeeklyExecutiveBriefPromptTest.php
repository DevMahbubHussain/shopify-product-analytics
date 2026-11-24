<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Mcp\Prompts\WeeklyExecutiveBriefPrompt;
use App\Mcp\Support\McpTokenService;
use App\Models\McpConsent;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'prompt-brief-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77031',
                'currency_code' => 'USD',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'installed_at' => now()->subDays(45),
            ]
        );

        DB::table('subscriptions')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77031',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/731',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Prompt Brief Client',
            staffRef: 'gid://shopify/Staff/731',
        );
    }
);

describe(
    'WeeklyExecutiveBriefPrompt', function (): void {
        it(
            'has proper prompt metadata and arguments', function (): void {
                $prompt = new WeeklyExecutiveBriefPrompt;

                expect($prompt->name())->toBe('weekly_executive_brief')
                    ->and($prompt->title())->toBe('Weekly Executive Brief')
                    ->and($prompt->description())->toContain('executive weekly');

                $arguments = $prompt->arguments();
                expect($arguments)->toHaveCount(1)
                    ->and($arguments[0]->name)->toBe('end_date')
                    ->and($arguments[0]->required)->toBeFalse()
                    ->and($arguments[0]->description)->toContain('End date');
            }
        );

        it(
            'lists the prompt via prompts/list JSON-RPC method', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-list-1',
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
                expect($names)->toContain('weekly_executive_brief');

                $brief = collect($response->json('result.prompts'))->firstWhere('name', 'weekly_executive_brief');
                expect($brief['arguments'])->toHaveCount(1)
                    ->and($brief['arguments'][0]['name'])->toBe('end_date');
            }
        );

        it(
            'generates a weekly brief prompt via prompts/get with explicit end_date', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-get-1',
                        'method' => 'prompts/get',
                        'params' => [
                            'name' => 'weekly_executive_brief',
                            'arguments' => [
                                'end_date' => '2026-05-20',
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
                        'Mcp-Name' => 'weekly_executive_brief',
                    ]
                );

                $response->assertStatus(200);

                $result = $response->json('result');
                expect($result['description'])->toContain('executive weekly');

                $message = $result['messages'][0];
                expect($message['role'])->toBe('user');

                $promptText = $message['content']['text'];

                // Verify exact 7-day date window (2026-05-14 to 2026-05-20)
                expect($promptText)->toContain('2026-05-14')
                    ->and($promptText)->toContain('2026-05-20')
                    ->and($promptText)->toContain('get_sync_status')
                    ->and($promptText)->toContain('get_analytics_overview')
                    ->and($promptText)->toContain('list_product_performance')
                    ->and($promptText)->toContain('data_complete');

                // Zero platform GIDs
                expect($promptText)->not->toContain('gid://shopify');
            }
        );

        it(
            'defaults to store yesterday when end_date argument is omitted', function (): void {
                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-get-def',
                        'method' => 'prompts/get',
                        'params' => [
                            'name' => 'weekly_executive_brief',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'prompts/get',
                        'Mcp-Name' => 'weekly_executive_brief',
                    ]
                );

                $response->assertStatus(200);
                $promptText = $response->json('result.messages.0.content.text');

                $yesterday = CarbonImmutable::yesterday($this->shop->timezone ?? 'UTC')->toDateString();
                $sevenDaysAgo = CarbonImmutable::yesterday($this->shop->timezone ?? 'UTC')->subDays(6)->toDateString();

                expect($promptText)->toContain($yesterday)
                    ->and($promptText)->toContain($sevenDaysAgo);
            }
        );

        it(
            'blocks free tier shops from accessing prompts', function (): void {
                DB::table('subscriptions')
                    ->where('shop_id', $this->shop->id)
                    ->update(['plan_key' => 'free']);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'prm-get-free',
                        'method' => 'prompts/get',
                        'params' => [
                            'name' => 'weekly_executive_brief',
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'prompts/get',
                        'Mcp-Name' => 'weekly_executive_brief',
                    ]
                );

                $response->assertStatus(403)
                    ->assertJsonPath('error', 'entitlement_denied');
            }
        );
    }
);

/** @param array<string, mixed> $arguments */
function weeklyBriefPromptGet(string $token, array $arguments): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'prompt-edge',
        'method' => 'prompts/get',
        'params' => [
            'name' => 'weekly_executive_brief',
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
        'Mcp-Name' => 'weekly_executive_brief',
    ]);
}

describe('WeeklyExecutiveBriefPrompt hardening', function (): void {
    it('never embeds the platform shop domain in the prompt text', function (): void {
        $text = weeklyBriefPromptGet($this->generatedToken->plaintextToken, ['end_date' => '2026-05-20'])
            ->assertOk()->json('result.messages.0.content.text');

        expect($text)->not->toContain('prompt-brief-store')
            ->and(strtolower($text))->not->toContain('shopify');
    });

    it('does not pass unsupported arguments to get_analytics_overview', function (): void {
        $text = weeklyBriefPromptGet($this->generatedToken->plaintextToken, ['end_date' => '2026-05-20'])
            ->assertOk()->json('result.messages.0.content.text');

        expect($text)->not->toContain('compare_previous');
    });

    it('rejects end_date values that are not YYYY-MM-DD', function (string $endDate): void {
        weeklyBriefPromptGet($this->generatedToken->plaintextToken, ['end_date' => $endDate])
            ->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, 'end date'));
    })->with(['natural language' => ['+5 years'], 'impossible date' => ['2026-02-30'], 'garbage' => ['abc']]);

    it('rejects an end_date in the future', function (): void {
        $tomorrow = \Carbon\CarbonImmutable::now($this->shop->timezone ?? 'UTC')->addDay()->toDateString();

        weeklyBriefPromptGet($this->generatedToken->plaintextToken, ['end_date' => $tomorrow])
            ->assertJsonPath('error.code', -32602);
    });
});
