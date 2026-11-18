<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

final readonly class OrderBatch
{
    /** @param list<OrderData> $orders */
    public function __construct(public array $orders)
    {
    }

    /** @param array{orders: list<array<string, mixed>>} $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(OrderData::fromArray(...), $data['orders']));
    }
}
