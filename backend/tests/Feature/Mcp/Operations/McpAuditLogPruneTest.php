<?php

declare(strict_types=1);

use App\Models\McpAuditLog;
use App\Models\Shop;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

function auditRowAged(string $shopId, int $daysOld): string
{
    $id = (string) Str::uuid();

    McpAuditLog::create([
        'id' => $id,
        'shop_id' => $shopId,
        'connection_type' => 'token',
        'connection_id' => (string) Str::uuid(),
        'method' => 'tools/call',
        'target_name' => 'get_sync_status',
        'status' => 'success',
        'duration_ms' => 5,
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now()->subDays($daysOld),
    ]);

    return $id;
}

beforeEach(function (): void {
    $this->shop = Shop::create([
        'shop_domain' => 'audit-prune.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now()->subYear(),
    ]);
});

it('prunes audit logs older than 90 days and keeps newer ones', function (): void {
    $old = auditRowAged($this->shop->id, 91);
    $edge = auditRowAged($this->shop->id, 89);
    $fresh = auditRowAged($this->shop->id, 1);

    $this->artisan('model:prune', ['--model' => [McpAuditLog::class]])->assertSuccessful();

    expect(McpAuditLog::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$edge, $fresh])->sort()->values()->all())
        ->and(McpAuditLog::query()->whereKey($old)->exists())->toBeFalse();
});

it('schedules audit log pruning daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'model:prune')
            && str_contains((string) $event->command, 'McpAuditLog'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
