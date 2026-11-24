<?php

declare(strict_types=1);

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Events\TenantDeactivated;
use App\Events\TenantPurgeRequested;
use App\Listeners\PurgeMcpCacheOnTenantPurge;
use App\Listeners\QueueMetricAggregationsFromOrders;
use App\Listeners\QueueMetricAggregationsFromRefunds;
use App\Listeners\RevokeMcpConnectionsOnTenantDeactivated;
use Illuminate\Support\Facades\Event;

/** @return list<string> */
function registeredListeners(string $event): array
{
    return array_map(
        static fn (mixed $listener): string => is_string($listener) ? $listener : get_debug_type($listener),
        Event::getRawListeners()[$event] ?? [],
    );
}

it('registers each domain event listener exactly once', function (string $event, string $listener): void {
    $listeners = registeredListeners($event);

    expect($listeners)->toHaveCount(1)
        ->and($listeners[0])->toStartWith($listener);
})->with([
    'orders' => [OrdersIngested::class, QueueMetricAggregationsFromOrders::class],
    'refunds' => [RefundsIngested::class, QueueMetricAggregationsFromRefunds::class],
    'deactivated' => [TenantDeactivated::class, RevokeMcpConnectionsOnTenantDeactivated::class],
    'purge' => [TenantPurgeRequested::class, PurgeMcpCacheOnTenantPurge::class],
]);
