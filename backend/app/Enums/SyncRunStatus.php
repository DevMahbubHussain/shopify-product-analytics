<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Pending, self::Running, self::Failed], true),
            self::Running => in_array($next, [self::Running, self::Completed, self::Failed], true),
            self::Completed, self::Failed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
