<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Jobs\RecordMcpAuditLogJob;
use App\Mcp\Support\McpTokenService;
use App\Models\McpConsent;
use App\Models\Shop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        Config::set('mcp.enabled', true);

        $this->shop = Shop::create(
            [
                'shop_domain' => 'audit-mw-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77098',
                'currency_code' => 'USD',
                'timezone' => 'America/Chicago',
                'status' => 'active',
                'installed_at' => now()->subDays(30),
            ]
        );

        DB::table('subscriptions')->insert(
            [
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shop->id,
                'shopify_subscription_gid' => 'gid://shopify/AppSubscription/77098',
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
                'granted_by_staff_ref' => 'gid://shopify/Staff/798',
                'policy_version' => 'v1.0',
                'granted_at' => now(),
            ]
        );

        $this->tokenService = app(McpTokenService::class);
        $this->generatedToken = $this->tokenService->createToken(
            shop: $this->shop,
            name: 'Audit Middleware Client',
            staffRef: 'gid://shopify/Staff/798',
        );
    }
);

describe(
    'AuditMcpRequest Middleware', function (): void {
        it(
            'blocks requests with 503 when mcp is disabled via kill switch', function (): void {
                Config::set('mcp.enabled', false);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'kill-sw-1',
                        'method' => 'tools/list',
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                    ]
                );

                $response->assertStatus(503)
                    ->assertJsonPath('error', 'mcp_disabled');
            }
        );

        it(
            'dispatches RecordMcpAuditLogJob on successful request execution', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'audit-log-1',
                        'method' => 'tools/list',
                        'params' => [
                            '_meta' => [
                                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                            ],
                        ],
                    ], [
                        'Authorization' => 'Bearer '.$this->generatedToken->plaintextToken,
                        'MCP-Protocol-Version' => '2026-07-28',
                        'Mcp-Method' => 'tools/list',
                        'X-MCP-Client' => 'Claude-Desktop/1.0',
                    ]
                );

                $response->assertStatus(200);

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class, function (RecordMcpAuditLogJob $job): bool {
                        return $job->shopId === $this->shop->id
                            && $job->method === 'tools/list'
                            && $job->clientName === 'Claude-Desktop/1.0'
                            && $job->protocolVersion === '2026-07-28'
                            && $job->status === 'success'
                            && $job->durationMs >= 0;
                    }
                );
            }
        );

        it(
            'dispatches RecordMcpAuditLogJob with target name on tool execution', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'audit-log-2',
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

                $response->assertStatus(200);

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class, function (RecordMcpAuditLogJob $job): bool {
                        return $job->shopId === $this->shop->id
                            && $job->method === 'tools/call'
                            && $job->targetName === 'get_sync_status'
                            && $job->status === 'success';
                    }
                );
            }
        );

        it(
            'dispatches RecordMcpAuditLogJob with rejected status when entitlement is denied', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                DB::table('subscriptions')
                    ->where('shop_id', $this->shop->id)
                    ->update(['plan_key' => 'free']);

                $response = $this->postJson(
                    '/mcp', [
                        'jsonrpc' => '2.0',
                        'id' => 'audit-log-3',
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'get_analytics_overview',
                            'arguments' => [
                                'from' => '2026-01-01',
                                'to' => '2026-01-07',
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

                $response->assertStatus(403);

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class, function (RecordMcpAuditLogJob $job): bool {
                        return $job->shopId === $this->shop->id
                            && $job->status === 'rejected';
                    }
                );
            }
        );
    }
);

/**
 * @param  array<string, mixed>  $params
 * @param  array<string, string>  $headers
 */
function auditedMcpCall(string $token, string $method, ?string $name, array $params, array $headers = []): TestResponse
{
    return test()->postJson(
        '/mcp',
        [
            'jsonrpc' => '2.0',
            'id' => 'audit-edge',
            'method' => $method,
            'params' => array_merge($params, [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                ],
            ]),
        ],
        array_merge(
            [
                'Authorization' => 'Bearer '.$token,
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => $method,
            ],
            $name !== null ? ['Mcp-Name' => $name] : [],
            $headers,
        ),
    );
}

describe(
    'AuditMcpRequest audit integrity', function (): void {
        it(
            'records the validated correlation id instead of a malformed client header', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', 'get_sync_status',
                    ['name' => 'get_sync_status', 'arguments' => (object) []],
                    ['X-Correlation-ID' => 'not-a-uuid'],
                )->assertOk();

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class,
                    fn (RecordMcpAuditLogJob $job): bool => Str::isUuid($job->correlationId)
                );
            }
        );

        it(
            'still writes an audit row when the client sends a malformed correlation id', function (): void {
                auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', 'get_sync_status',
                    ['name' => 'get_sync_status', 'arguments' => (object) []],
                    ['X-Correlation-ID' => 'not-a-uuid'],
                )->assertOk();

                expect(DB::table('mcp_audit_logs')->where('shop_id', $this->shop->id)->count())->toBe(1)
                    ->and(DB::table('failed_jobs')->count())->toBe(0);
            }
        );

        it(
            'truncates oversized client-supplied values to their column sizes', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                $longName = str_repeat('x', 300);

                auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', $longName,
                    ['name' => $longName, 'arguments' => (object) []],
                );

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class,
                    fn (RecordMcpAuditLogJob $job): bool => mb_strlen((string) $job->targetName) <= 128
                        && mb_strlen($job->method) <= 64
                        && mb_strlen((string) $job->protocolVersion) <= 16
                        && mb_strlen((string) $job->clientName) <= 100
                );
            }
        );

        it(
            'still writes an audit row when the tool name exceeds the column size', function (): void {
                $longName = str_repeat('x', 300);

                auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', $longName,
                    ['name' => $longName, 'arguments' => (object) []],
                );

                expect(DB::table('mcp_audit_logs')->where('shop_id', $this->shop->id)->count())->toBe(1)
                    ->and(DB::table('failed_jobs')->count())->toBe(0);
            }
        );

        it(
            'records tool_error when a tool result has isError', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', 'get_analytics_overview',
                    ['name' => 'get_analytics_overview', 'arguments' => ['from' => 'bad', 'to' => 'bad']],
                )->assertOk()->assertJsonPath('result.isError', true);

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class,
                    fn (RecordMcpAuditLogJob $job): bool => $job->status === 'tool_error'
                );
            }
        );

        it(
            'records protocol_error when the JSON-RPC response carries an error', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', 'no_such_tool',
                    ['name' => 'no_such_tool', 'arguments' => (object) []],
                );

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class,
                    fn (RecordMcpAuditLogJob $job): bool => $job->status === 'protocol_error'
                );
            }
        );

        it(
            'records rate_limited when the per-connection limit is hit', function (): void {
                Queue::fake([RecordMcpAuditLogJob::class]);

                RateLimiter::for(
                    'mcp',
                    fn (): Limit => Limit::perMinute(1)->by('audit-test'),
                );

                $call = fn () => auditedMcpCall(
                    $this->generatedToken->plaintextToken, 'tools/call', 'get_sync_status',
                    ['name' => 'get_sync_status', 'arguments' => (object) []],
                );

                $call()->assertOk();
                $call()->assertStatus(429);

                Queue::assertPushed(
                    RecordMcpAuditLogJob::class,
                    fn (RecordMcpAuditLogJob $job): bool => $job->status === 'rate_limited'
                );
            }
        );
    }
);
