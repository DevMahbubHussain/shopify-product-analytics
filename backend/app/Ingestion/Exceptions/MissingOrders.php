<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

final class MissingOrders extends IngestionException
{
    /** @param list<string> $missingOrderGids */
    public function __construct(public readonly array $missingOrderGids)
    {
        parent::__construct('Referenced orders are not ingested yet.');
    }

    public function status(): int
    {
        return 409;
    }

    /** @return array{missing_order_gids: list<string>} */
    public function details(): array
    {
        return ['missing_order_gids' => $this->missingOrderGids];
    }
}
