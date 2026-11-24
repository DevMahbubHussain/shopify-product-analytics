<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use App\Enums\ShopStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $shop_domain
 * @property ShopStatus $status
 * @property Platform $platform
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 * @property-read Subscription|null $activeSubscription
 */
final class Shop extends Model
{
    use HasUuids;

    /** @var array<string, string> */
    protected $attributes = ['platform' => Platform::Shopify->value];

    protected $fillable = ['shop_domain', 'shopify_shop_gid', 'currency_code', 'timezone', 'status', 'installed_at', 'uninstalled_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'platform' => Platform::class,
            'installed_at' => 'immutable_datetime',
            'uninstalled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'ACTIVE')->orderByDesc('created_at');
    }
}
