<?php

declare(strict_types=1);

use App\Jobs\RecordMcpAuditLogJob;
use App\Models\McpAuditLog;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(
    function (): void {
        $this->shop = Shop::create(
            [
                'shop_domain' => 'audit-job-store.myshopify.com',
                'shopify_shop_gid' => 'gid://shopify/Shop/77099',
                'currency_code' => 'USD',
                'timezone' => 'America/Chicago',
                'status' => 'active',
                'installed_at' => now()->subDays(30),
            ]
        );
    }
);

describe(
    'RecordMcpAuditLogJob', function (): void {
        it(
            'persists an mcp audit log record into the database', function (): void {
                $correlationId = (string) Str::uuid();
                $connectionId = (string) Str::uuid();
                $timestamp = CarbonImmutable::now();

                $job = new RecordMcpAuditLogJob(
                    shopId: $this->shop->id,
                    connectionType: 'token',
                    connectionId: $connectionId,
                    method: 'tools/call',
                    targetName: 'get_analytics_overview',
                    status: 'success',
                    durationMs: 42,
                    clientName: 'Claude Desktop',
                    protocolVersion: '2026-07-28',
                    correlationId: $correlationId,
                    createdAt: $timestamp,
                );

                $job->handle();

                $record = McpAuditLog::query()->where('correlation_id', $correlationId)->first();

                expect($record)->not->toBeNull()
                    ->and($record->shop_id)->toBe($this->shop->id)
                    ->and($record->connection_type)->toBe('token')
                    ->and($record->connection_id)->toBe($connectionId)
                    ->and($record->method)->toBe('tools/call')
                    ->and($record->target_name)->toBe('get_analytics_overview')
                    ->and($record->status)->toBe('success')
                    ->and($record->duration_ms)->toBe(42)
                    ->and($record->client_name)->toBe('Claude Desktop')
                    ->and($record->protocol_version)->toBe('2026-07-28');
            }
        );

        it(
            'handles nullable fields gracefully', function (): void {
                $correlationId = (string) Str::uuid();

                $job = new RecordMcpAuditLogJob(
                    shopId: $this->shop->id,
                    connectionType: 'token',
                    connectionId: null,
                    method: 'prompts/list',
                    targetName: null,
                    status: 'success',
                    durationMs: 15,
                    clientName: null,
                    protocolVersion: null,
                    correlationId: $correlationId,
                );

                $job->handle();

                $record = McpAuditLog::query()->where('correlation_id', $correlationId)->first();

                expect($record)->not->toBeNull()
                    ->and($record->connection_id)->toBeNull()
                    ->and($record->target_name)->toBeNull()
                    ->and($record->client_name)->toBeNull()
                    ->and($record->protocol_version)->toBeNull()
                    ->and($record->status)->toBe('success');
            }
        );
    }
);

it('declares the standard retry policy for queued jobs', function (): void {
    $job = new RecordMcpAuditLogJob(
        shopId: (string) Str::uuid(),
        connectionType: 'token',
        connectionId: (string) Str::uuid(),
        method: 'tools/call',
        targetName: 'get_sync_status',
        status: 'success',
        durationMs: 5,
        clientName: null,
        protocolVersion: '2026-07-28',
        correlationId: (string) Str::uuid(),
    );

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([5, 15, 60]);
});
