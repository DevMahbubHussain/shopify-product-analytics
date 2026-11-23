<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('registers outbox recovery and prune commands in the Laravel schedule', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $events = collect($schedule->events());

    // 1. Verify outbox:publish schedule
    $publishEvent = $events->first(fn (Event $e): bool => str_contains((string) $e->command, 'outbox:publish'));

    expect($publishEvent)->not->toBeNull()
        ->and($publishEvent->expression)->toBe('* * * * *')
        ->and($publishEvent->withoutOverlapping)->toBeTrue()
        ->and((string) $publishEvent->command)->toContain('--age=30')
        ->and((string) $publishEvent->command)->toContain('--limit=100');

    // 2. Verify outbox:prune schedule
    $pruneEvent = $events->first(fn (Event $e): bool => str_contains((string) $e->command, 'outbox:prune'));

    expect($pruneEvent)->not->toBeNull()
        ->and($pruneEvent->expression)->toBe('0 0 * * *')
        ->and($pruneEvent->withoutOverlapping)->toBeTrue()
        ->and((string) $pruneEvent->command)->toContain('--days=14');
});
