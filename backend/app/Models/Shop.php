<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShopStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $shop_domain
 * @property ShopStatus $status
 */
final class Shop extends Model
{
    use HasUuids;

    protected $fillable = ['shop_domain', 'shopify_shop_gid', 'currency_code', 'timezone', 'status', 'installed_at', 'uninstalled_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'installed_at' => 'immutable_datetime',
            'uninstalled_at' => 'immutable_datetime',
        ];
    }
}
