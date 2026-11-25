<?php

declare(strict_types=1);

namespace App\Events;

final readonly class TenantPurgeRequested
{
    public function __construct(
        public string $shopId,
    ) {}
}
