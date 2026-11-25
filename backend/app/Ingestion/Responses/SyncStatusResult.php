<?php

declare(strict_types=1);

namespace App\Ingestion\Responses;

use App\Enums\SyncRunStatus;
use App\Ingestion\IngestionResponse;

final readonly class SyncStatusResult implements IngestionResponse
{
    public function __construct(public string $syncRunId, public SyncRunStatus $syncStatus, public bool $created) {}

    public function status(): int
    {
        return $this->created ? 201 : 200;
    }

    /** @return array{sync_run_id: string, status: string} */
    public function jsonSerialize(): array
    {
        return ['sync_run_id' => $this->syncRunId, 'status' => $this->syncStatus->value];
    }
}
