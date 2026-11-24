<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $shop_id
 * @property string $connection_type
 * @property string|null $connection_id
 * @property string $method
 * @property string|null $target_name
 * @property string $status
 * @property int $duration_ms
 * @property string|null $client_name
 * @property string|null $protocol_version
 * @property string $correlation_id
 * @property CarbonImmutable $created_at
 * @property-read Shop $shop
 */
final class McpAuditLog extends Model
{
    use HasUuids;
    use MassPrunable;

    /** PRD §10.1: rolling 90-day retention for audit metadata. */
    public const RETENTION_DAYS = 90;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'shop_id',
        'connection_type',
        'connection_id',
        'method',
        'target_name',
        'status',
        'duration_ms',
        'client_name',
        'protocol_version',
        'correlation_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<$this> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
