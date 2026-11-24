<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $shop_id
 * @property Platform $platform
 * @property string $granted_by_staff_ref
 * @property string $policy_version
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $withdrawn_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Shop $shop
 */
final class McpConsent extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'shop_id',
        'platform',
        'granted_by_staff_ref',
        'policy_version',
        'granted_at',
        'withdrawn_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'granted_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @param  Builder< $this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }
}
