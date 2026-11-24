<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property SyncRun $resource */
final class SyncRunResource extends JsonResource
{
    public function __construct(SyncRun $syncRun)
    {
        parent::__construct($syncRun);
    }

    /** @return array<string, string|int|null> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            'id' => $run->id,
            'sync_type' => $run->sync_type->value,
            'status' => $run->status->value,
            'records_received' => $run->records_received,
            'records_processed' => $run->records_processed,
            'started_at' => $run->started_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'error_code' => $run->error_code,
            'history_notice' => $run->history_notice,
            'last_activity_at' => $run->updated_at?->toISOString(),
        ];
    }
}
