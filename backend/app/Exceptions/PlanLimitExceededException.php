<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PlanTier;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly PlanTier $tier,
        public readonly int $maxDays,
        string $message = 'Plan date range limit exceeded.',
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'plan_limit_exceeded',
            'message' => $this->getMessage(),
            'plan' => $this->tier->value,
            'max_days' => $this->maxDays,
        ], 403);
    }
}
