<?php

declare(strict_types=1);

use App\Enums\PlanTier;

describe('PlanTier MCP Entitlements', function (): void {
    it('restricts MCP access to Pro tier and blocks Free tier', function (): void {
        expect(PlanTier::Free->canUseMcp())->toBeFalse()
            ->and(PlanTier::Pro->canUseMcp())->toBeTrue();
    });

    it('enforces tier-specific rate limits for MCP access', function (): void {
        expect(PlanTier::Free->mcpRateLimitPerMinute())->toBe(0)
            ->and(PlanTier::Free->mcpRateLimitPerHour())->toBe(0)
            ->and(PlanTier::Pro->mcpRateLimitPerMinute())->toBe(60)
            ->and(PlanTier::Pro->mcpRateLimitPerHour())->toBe(600);
    });

    it('matches max analytics days between standard analytics and MCP', function (): void {
        expect(PlanTier::Free->maxAnalyticsDays())->toBe(30)
            ->and(PlanTier::Pro->maxAnalyticsDays())->toBe(365);
    });
});
