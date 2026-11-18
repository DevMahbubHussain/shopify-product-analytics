<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\SyncStatusData;
use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use Illuminate\Validation\Rule;

final class ReportSyncStatusRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sync_run_id' => ['nullable', 'uuid'],
            'sync_type' => ['required_without:sync_run_id', Rule::enum(SyncType::class)],
            'status' => ['required', Rule::enum(SyncRunStatus::class)],
            'records_received' => ['nullable', 'integer', 'min:0'],
            'records_processed' => ['nullable', 'integer', 'min:0'],
            'cursor_metadata' => ['nullable', 'array'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:64'],
            'error_summary' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toDto(): SyncStatusData
    {
        return SyncStatusData::fromArray($this->validated());
    }
}
