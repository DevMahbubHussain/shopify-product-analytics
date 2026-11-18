<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $shop_id
 * @property SyncType $sync_type
 * @property SyncRunStatus $status
 * @property int $records_received
 * @property int $records_processed
 */
final class SyncRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'shop_id', 'sync_type', 'status', 'started_at', 'completed_at', 'cursor_metadata',
        'records_received', 'records_processed', 'error_code', 'error_summary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sync_type' => SyncType::class,
            'status' => SyncRunStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cursor_metadata' => 'array',
            'records_received' => 'integer',
            'records_processed' => 'integer',
        ];
    }
}
