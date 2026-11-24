<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

use App\Enums\SyncRunStatus;
use App\Enums\SyncType;

final readonly class SyncStatusData
{
    /** @param array<string, mixed>|null $cursorMetadata */
    public function __construct(
        public ?string $syncRunId,
        public ?SyncType $syncType,
        public SyncRunStatus $status,
        public ?int $recordsReceived,
        public ?int $recordsProcessed,
        public ?array $cursorMetadata,
        public ?string $errorCode,
        public ?string $errorSummary,
        public ?string $historyNotice,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['sync_run_id'] ?? null,
            isset($data['sync_type']) ? SyncType::from($data['sync_type']) : null,
            SyncRunStatus::from($data['status']),
            isset($data['records_received']) ? (int) $data['records_received'] : null,
            isset($data['records_processed']) ? (int) $data['records_processed'] : null,
            $data['cursor_metadata'] ?? null,
            $data['error_code'] ?? null,
            $data['error_summary'] ?? null,
            $data['history_notice'] ?? null,
        );
    }
}
