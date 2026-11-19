<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Data\Ingestion\SyncStatusData;
use App\Enums\SyncRunStatus;
use App\Ingestion\Exceptions\IllegalSyncTransition;
use App\Ingestion\Exceptions\SyncRunAlreadyInProgress;
use App\Ingestion\Exceptions\UnknownSyncRun;
use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Responses\SyncStatusResult;
use App\Models\SyncRun;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordSyncStatus
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        $data = $context->payload(SyncStatusData::class);
        $shopId = $context->shop()->id;

        $context->response = $data->syncRunId === null
            ? $this->start($shopId, $data)
            : DB::transaction(fn (): SyncStatusResult => $this->advance($shopId, $data), 5);

        return $next($context);
    }

    private function start(string $shopId, SyncStatusData $data): SyncStatusResult
    {
        return DB::transaction(function () use ($shopId, $data): SyncStatusResult {
            SyncRun::query()
                ->where('shop_id', $shopId)
                ->whereIn('status', [SyncRunStatus::Running, SyncRunStatus::Pending])
                ->where('updated_at', '<', now()->subMinutes(10))
                ->update([
                    'status' => SyncRunStatus::Failed->value,
                    'completed_at' => now(),
                    'error_code' => 'STALLED',
                    'error_summary' => 'Sync run abandoned after 10 minutes of inactivity.',
                ]);

            $alreadyRunning = SyncRun::query()
                ->where('shop_id', $shopId)
                ->whereIn('status', [SyncRunStatus::Running, SyncRunStatus::Pending])
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw new SyncRunAlreadyInProgress();
            }

            $run = SyncRun::query()->create([
                'shop_id' => $shopId,
                'sync_type' => $data->syncType,
                'status' => $data->status,
                'records_received' => $data->recordsReceived ?? 0,
                'records_processed' => $data->recordsProcessed ?? 0,
                'cursor_metadata' => $data->cursorMetadata,
                ...$this->statusFields(null, $data),
            ]);

            return new SyncStatusResult($run->id, $run->status, created: true);
        }, 5);
    }

    private function advance(string $shopId, SyncStatusData $data): SyncStatusResult
    {
        $run = SyncRun::query()->whereKey($data->syncRunId)->where('shop_id', $shopId)->lockForUpdate()->first()
            ?? throw new UnknownSyncRun();

        if (! $run->status->canTransitionTo($data->status)) {
            throw new IllegalSyncTransition($run->status, $data->status);
        }

        $this->assertCountersMonotonic($run, $data);

        $run->update([
            'status' => $data->status,
            'records_received' => $data->recordsReceived ?? $run->records_received,
            'records_processed' => $data->recordsProcessed ?? $run->records_processed,
            ...$this->statusFields($run, $data),
        ]);

        return new SyncStatusResult($run->id, $run->status, created: false);
    }

    /** @return array<string, mixed> */
    private function statusFields(?SyncRun $run, SyncStatusData $data): array
    {
        $fields = array_filter([
            'cursor_metadata' => $data->cursorMetadata,
            'error_code' => $data->errorCode,
            'error_summary' => $data->errorSummary,
        ], fn (mixed $value): bool => $value !== null);

        if ($data->status !== SyncRunStatus::Pending && $run?->started_at === null) {
            $fields['started_at'] = now();
        }

        if ($data->status->isTerminal()) {
            $fields['completed_at'] = now();
        }

        return $fields;
    }

    private function assertCountersMonotonic(SyncRun $run, SyncStatusData $data): void
    {
        $errors = [];
        if ($data->recordsReceived !== null && $data->recordsReceived < $run->records_received) {
            $errors['records_received'] = 'Counter cannot decrease.';
        }
        if ($data->recordsProcessed !== null && $data->recordsProcessed < $run->records_processed) {
            $errors['records_processed'] = 'Counter cannot decrease.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
