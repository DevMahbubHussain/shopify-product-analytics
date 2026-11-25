<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TARGET_SHOP_ROWS = 2000;
const NOISE_SHOPS = 40;
const NOISE_ROWS_PER_SHOP = 1000;

beforeEach(function (): void {
    $this->shopDomain = 'acme.myshopify.com';
    $this->shopId = installShop($this->shopDomain);

    DB::statement(
        "INSERT INTO shops (id, shop_domain, shopify_shop_gid, currency_code, timezone, status, platform, installed_at, created_at, updated_at)
         SELECT gen_random_uuid(), 'noise-' || g || '.myshopify.com', 'gid://shopify/Shop/9' || g, 'USD', 'UTC', 'active', 'shopify', now(), now(), now()
         FROM generate_series(1, ?) g",
        [NOISE_SHOPS],
    );

    $insertLogs = "INSERT INTO mcp_audit_logs (id, shop_id, connection_type, connection_id, method, target_name, status, duration_ms, client_name, protocol_version, correlation_id, created_at)
        SELECT gen_random_uuid(), s.id, 'token', gen_random_uuid(), 'tools/call', 'get_analytics_overview',
               CASE WHEN g % 20 = 0 THEN 'rejected' ELSE 'success' END, 5 + (g % 90), 'Bench/1.0', '2026-07-28', gen_random_uuid(),
               TIMESTAMPTZ '2026-11-01 00:00:00+00' - (g || ' minutes')::interval
        FROM shops s CROSS JOIN generate_series(1, ?) g
        WHERE s.shop_domain {filter}";

    DB::statement(str_replace('{filter}', '= ?', $insertLogs), [TARGET_SHOP_ROWS, $this->shopDomain]);
    DB::statement(str_replace('{filter}', "LIKE 'noise-%'", $insertLogs), [NOISE_ROWS_PER_SHOP]);
    DB::statement('ANALYZE mcp_audit_logs');
});

function auditPlan(string $sql, string $shopId): string
{
    return collect(DB::select('EXPLAIN ANALYZE '.$sql, [$shopId]))->pluck('QUERY PLAN')->implode("\n");
}

it('uses the shop/created_at composite index for first page, deep page and total count', function (string $sql): void {
    $plan = auditPlan($sql, $this->shopId);

    expect($plan)->toContain('mcp_audit_logs_shop_created_idx')
        ->and($plan)->not->toContain('Seq Scan on mcp_audit_logs');
})->with([
    'first page' => ['SELECT * FROM mcp_audit_logs WHERE shop_id = ? ORDER BY created_at DESC, id DESC LIMIT 25 OFFSET 0'],
    'deep page' => ['SELECT * FROM mcp_audit_logs WHERE shop_id = ? ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET 1900'],
    'total count' => ['SELECT count(*) FROM mcp_audit_logs WHERE shop_id = ?'],
]);

it('keeps a constant query count regardless of page or page size', function (): void {
    $counts = [];
    foreach (['?per_page=25&page=1', '?per_page=100&page=7', '?per_page=10&page=200'] as $query) {
        $executed = 0;
        DB::listen(function () use (&$executed): void {
            $executed++;
        });
        s2sGet('/internal/v1/mcp/audit-logs'.$query, $this->shopDomain)->assertOk();
        $counts[] = $executed;
        DB::getEventDispatcher()?->forget('Illuminate\Database\Events\QueryExecuted');
    }

    expect(array_unique($counts))->toHaveCount(1)
        ->and($counts[0])->toBeLessThanOrEqual(4);
});

it('serves pages with p50 < 30ms and p95 < 60ms', function (): void {
    s2sGet('/internal/v1/mcp/audit-logs', $this->shopDomain)->assertOk();

    $samples = [];
    for ($i = 1; $i <= 30; $i++) {
        $page = ($i % 20) + 1;
        $start = hrtime(true);
        s2sGet("/internal/v1/mcp/audit-logs?per_page=100&page={$page}", $this->shopDomain)->assertOk();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    sort($samples);

    $p50 = $samples[(int) floor(count($samples) * 0.50)];
    $p95 = $samples[(int) floor(count($samples) * 0.95) - 1];

    expect($p50)->toBeLessThan(30.0)
        ->and($p95)->toBeLessThan(60.0);
});

it('holds engine heap flat across sequential page reads', function (): void {
    s2sGet('/internal/v1/mcp/audit-logs?per_page=100', $this->shopDomain)->assertOk();

    gc_collect_cycles();
    $before = memory_get_usage(false);

    for ($page = 1; $page <= 20; $page++) {
        s2sGet("/internal/v1/mcp/audit-logs?per_page=100&page={$page}", $this->shopDomain)->assertOk();
    }

    gc_collect_cycles();
    $deltaMb = (memory_get_usage(false) - $before) / (1024 * 1024);

    expect($deltaMb)->toBeLessThan(2.0);
});
