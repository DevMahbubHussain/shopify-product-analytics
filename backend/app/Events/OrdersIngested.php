<?php

declare(strict_types=1);

namespace App\Events;

final readonly class OrdersIngested
{
    /** @param list<string> $orderIds */
    public function __construct(public string $shopId, public array $orderIds)
    {
    }
}
