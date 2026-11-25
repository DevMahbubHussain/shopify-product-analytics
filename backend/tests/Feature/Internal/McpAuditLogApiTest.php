<?php

declare(strict_types=1);

use App\Enums\ShopStatus;
use App\Models\McpAuditLog;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function auditLog(string $shopId, array $overrides = []): McpAuditLog
{
    return McpAuditLog::create([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'connection_type' => 'token',
        'connection_id' => (string) Str::uuid(),
        'method' => 'tools/call',
        'target_name' => 'get_sync_status',
        'status' => 'success',
        'duration_ms' => 10,
        'client_name' => 'Test',
        'protocol_version' => '2026-07-28',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now(),
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->shopId = installShop('acme.myshopify.com');
    $this->otherShopId = installShop('other.myshopify.com');
});

describe('Internal S2S MCP Audit Logs API', function (): void {
    it('requires valid S2S authentication', function (): void {
        $response = $this->getJson('/internal/v1/mcp/audit-logs');
        $response->assertStatus(401);
    });

    it('returns empty audit log list when no operations have occurred', function (): void {
        $response = s2sGet('/internal/v1/mcp/audit-logs');

        $response->assertOk()
            ->assertJson([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'total' => 0,
                    'per_page' => 25,
                ],
            ]);
    });

    it('returns paginated audit logs ordered by created_at descending', function (): void {
        $now = CarbonImmutable::now();

        // Create 3 logs for this shop at different times
        $log1 = McpAuditLog::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'connection_type' => 'token',
            'connection_id' => (string) Str::uuid(),
            'method' => 'tools/call',
            'target_name' => 'get_sync_status',
            'status' => 'success',
            'duration_ms' => 12,
            'client_name' => 'Claude-Desktop/1.0',
            'protocol_version' => '2026-07-28',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => $now->subMinutes(10),
        ]);

        $log2 = McpAuditLog::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'connection_type' => 'token',
            'connection_id' => (string) Str::uuid(),
            'method' => 'tools/call',
            'target_name' => 'get_analytics_overview',
            'status' => 'success',
            'duration_ms' => 45,
            'client_name' => 'Cursor/0.40',
            'protocol_version' => '2026-07-28',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => $now->subMinutes(5),
        ]);

        $log3 = McpAuditLog::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'connection_type' => 'token',
            'connection_id' => (string) Str::uuid(),
            'method' => 'prompts/get',
            'target_name' => 'weekly_executive_brief',
            'status' => 'rejected',
            'duration_ms' => 8,
            'client_name' => 'Claude-Desktop/1.0',
            'protocol_version' => '2026-07-28',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => $now->subMinute(),
        ]);

        $response = s2sGet('/internal/v1/mcp/audit-logs');

        $response->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $log3->id)
            ->assertJsonPath('data.0.method', 'prompts/get')
            ->assertJsonPath('data.0.target_name', 'weekly_executive_brief')
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.duration_ms', 8)
            ->assertJsonPath('data.1.id', $log2->id)
            ->assertJsonPath('data.1.method', 'tools/call')
            ->assertJsonPath('data.1.target_name', 'get_analytics_overview')
            ->assertJsonPath('data.2.id', $log1->id);
    });

    it('strictly isolates audit logs between different shop tenants', function (): void {
        McpAuditLog::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->otherShopId,
            'connection_type' => 'token',
            'connection_id' => (string) Str::uuid(),
            'method' => 'tools/call',
            'target_name' => 'get_sales_trend',
            'status' => 'success',
            'duration_ms' => 20,
            'client_name' => 'External Tenant Client',
            'protocol_version' => '2026-07-28',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $response = s2sGet('/internal/v1/mcp/audit-logs');

        $response->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    });

    it('respects per_page query parameter capped at 100', function (): void {
        for ($i = 0; $i < 15; $i++) {
            McpAuditLog::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $this->shopId,
                'connection_type' => 'token',
                'connection_id' => (string) Str::uuid(),
                'method' => 'tools/call',
                'target_name' => 'get_sync_status',
                'status' => 'success',
                'duration_ms' => 10,
                'client_name' => 'Test',
                'protocol_version' => '2026-07-28',
                'correlation_id' => (string) Str::uuid(),
                'created_at' => now()->subMinutes(15 - $i),
            ]);
        }

        $response = s2sGet('/internal/v1/mcp/audit-logs?per_page=5&page=1');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(5, 'data');
    });

    it('rejects per_page above 100', function (): void {
        s2sGet('/internal/v1/mcp/audit-logs?per_page=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    });

    it('rejects non-positive or non-numeric pagination values', function (string $query, string $field): void {
        s2sGet('/internal/v1/mcp/audit-logs?'.$query)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'per_page zero' => ['per_page=0', 'per_page'],
        'per_page negative' => ['per_page=-1', 'per_page'],
        'per_page text' => ['per_page=abc', 'per_page'],
        'page zero' => ['page=0', 'page'],
        'page text' => ['page=abc', 'page'],
    ]);

    it('accepts exactly 100 per page', function (): void {
        s2sGet('/internal/v1/mcp/audit-logs?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    });

    it('returns an empty page with the real total when the page is past the end', function (): void {
        auditLog($this->shopId);
        auditLog($this->shopId);

        s2sGet('/internal/v1/mcp/audit-logs?page=9')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.current_page', 9);
    });

    it('pages deterministically when rows share the same created_at', function (): void {
        $sameInstant = CarbonImmutable::parse('2026-11-20 10:00:00.000000');
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = auditLog($this->shopId, ['created_at' => $sameInstant])->id;
        }
        rsort($ids, SORT_STRING);

        $page1 = s2sGet('/internal/v1/mcp/audit-logs?per_page=3&page=1')->assertOk()->json('data.*.id');
        $page2 = s2sGet('/internal/v1/mcp/audit-logs?per_page=3&page=2')->assertOk()->json('data.*.id');

        expect([...$page1, ...$page2])->toBe($ids);
    });

    it('stores created_at with microsecond precision and orders by it', function (): void {
        $base = CarbonImmutable::parse('2026-11-25 10:03:38.000000');
        $first = auditLog($this->shopId, ['method' => 'initialize', 'created_at' => $base->addMicroseconds(100)]);
        $second = auditLog($this->shopId, ['method' => 'tools/list', 'created_at' => $base->addMicroseconds(200)]);
        $third = auditLog($this->shopId, ['method' => 'tools/call', 'created_at' => $base->addMicroseconds(300)]);

        $stored = DB::table('mcp_audit_logs')->where('id', $third->id)->value(DB::raw("to_char(created_at AT TIME ZONE 'UTC', 'US')"));
        expect($stored)->toBe('000300');

        s2sGet('/internal/v1/mcp/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.2.id', $first->id);
    });

    it('returns 404 for an unknown shop', function (): void {
        s2sGet('/internal/v1/mcp/audit-logs', 'ghost.myshopify.com')->assertNotFound();
    });

    it('returns 403 for an uninstalled shop', function (): void {
        Shop::query()->whereKey($this->shopId)->update(['status' => ShopStatus::Uninstalled->value]);

        s2sGet('/internal/v1/mcp/audit-logs')->assertForbidden();
    });
});
