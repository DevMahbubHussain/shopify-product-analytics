<?php

declare(strict_types=1);

namespace App\Ingestion;

use Illuminate\Support\Facades\DB;
use Stringable;

final class TenantIdLookup
{
    /**
     * @param list<Stringable|string|null> $gids
     * @return array<string, string> gid => id, limited to rows owned by the shop
     */
    public function byGid(string $table, string $gidColumn, string $shopId, array $gids): array
    {
        $gids = array_values(array_unique(array_filter(array_map(
            fn (Stringable|string|null $gid): ?string => $gid === null ? null : (string) $gid,
            $gids,
        ))));

        return $gids === []
            ? []
            : DB::table($table)->where('shop_id', $shopId)->whereIn($gidColumn, $gids)->pluck('id', $gidColumn)->all();
    }
}
