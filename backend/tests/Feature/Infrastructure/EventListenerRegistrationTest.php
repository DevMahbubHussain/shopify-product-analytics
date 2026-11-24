<?php

declare(strict_types=1);

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use App\Listeners\QueueMetricAggregationsFromOrders;
use App\Listeners\QueueMetricAggregationsFromRefunds;
use Illuminate\Support\Facades\Event;

/** @return list<string> */
function registeredListeners(string $event): array
{
    return array_map(
        static fn (mixed $listener): string => is_string($listener) ? $listener : get_debug_type($listener),
        Event::getRawListeners()[$event] ?? [],
    );
}

it('registers each ingestion listener exactly once', function (string $event, string $listener): void {
    $listeners = registeredListeners($event);

    expect($listeners)->toHaveCount(1)
        ->and($listeners[0])->toStartWith($listener);
})->with([
    'orders' => [OrdersIngested::class, QueueMetricAggregationsFromOrders::class],
    'refunds' => [RefundsIngested::class, QueueMetricAggregationsFromRefunds::class],
]);
