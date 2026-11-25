<?php

declare(strict_types=1);

namespace App\Ingestion\Responses;

use App\Ingestion\IngestionResponse;

final readonly class BatchResult implements IngestionResponse
{
    public function __construct(public int $received, public int $written) {}

    public function status(): int
    {
        return 200;
    }

    /** @return array{received: int, written: int, skipped_stale: int} */
    public function jsonSerialize(): array
    {
        return ['received' => $this->received, 'written' => $this->written, 'skipped_stale' => $this->received - $this->written];
    }
}
