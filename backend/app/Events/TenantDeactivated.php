<?php

declare(strict_types=1);

namespace App\Events;

use Carbon\CarbonImmutable;

final readonly class TenantDeactivated
{
    public function __construct(
        public string $shopId,
        public CarbonImmutable $deactivatedAt,
    ) {}
}
