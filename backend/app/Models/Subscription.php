<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property      string $id
 * @property      string $shop_id
 * @property      string|null $shopify_subscription_gid
 * @property      string $plan_key
 * @property      string $status
 * @property      \Carbon\CarbonImmutable|null $trial_ends_at
 * @property      \Carbon\CarbonImmutable|null $current_period_end
 * @property      \Carbon\CarbonImmutable $created_at
 * @property      \Carbon\CarbonImmutable $updated_at
 * @property-read Shop $shop
 */
final class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'shop_id',
        'shopify_subscription_gid',
        'plan_key',
        'status',
        'trial_ends_at',
        'current_period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
