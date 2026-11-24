<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanTier: string
{
    case Free = 'free';
    case Pro = 'pro';

    public static function fromPlanKey(?string $planKey): self
    {
        return match (strtolower((string) $planKey)) {
            'pro', 'growth' => self::Pro,
            default => self::Free,
        };
    }

    public function maxAnalyticsDays(): int
    {
        return match ($this) {
            self::Free => 30,
            self::Pro => 365,
        };
    }

    public function canExportCsv(): bool
    {
        return match ($this) {
            self::Free => false,
            self::Pro => true,
        };
    }

    public function canUseMcp(): bool
    {
        return match ($this) {
            self::Free => false,
            self::Pro => true,
        };
    }

    public function mcpRateLimitPerMinute(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 60,
        };
    }

    public function mcpRateLimitPerHour(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 600,
        };
    }
}
