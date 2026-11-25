<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $shop_id
 * @property string $export_type
 * @property string $file_name
 * @property string|null $file_path
 * @property int $row_count
 * @property string $status
 * @property string $date_from
 * @property string $date_to
 * @property string|null $error_message
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Shop $shop
 */
final class Export extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'shop_id',
        'export_type',
        'file_name',
        'file_path',
        'row_count',
        'status',
        'date_from',
        'date_to',
        'error_message',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
