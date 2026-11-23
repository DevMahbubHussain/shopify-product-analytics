<?php

declare(strict_types=1);

namespace App\Events;

final readonly class OrdersIngested
{
    /**
     * @param list<string> $orderIds
     * @param list<array{product_id: string, metric_date: string}> $affectedBuckets
     */
    public function __construct(
        public string $shopId,
        public array $orderIds,
        public array $affectedBuckets = []
    ) {
    }
}
