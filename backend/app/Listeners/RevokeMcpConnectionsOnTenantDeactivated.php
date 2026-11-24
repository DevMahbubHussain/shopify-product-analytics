<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TenantDeactivated;
use App\Models\McpConsent;
use App\Models\McpToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RevokeMcpConnectionsOnTenantDeactivated
{
    public function handle(TenantDeactivated $event): void
    {
        /** @var list<string> $tokenHashes */
        $tokenHashes = DB::transaction(
            static function () use ($event): array {
                $hashes = McpToken::query()
                    ->where('shop_id', $event->shopId)
                    ->whereNull('revoked_at')
                    ->pluck('token_hash')
                    ->all();

                McpToken::query()
                    ->where('shop_id', $event->shopId)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $event->deactivatedAt]);

                McpConsent::query()
                    ->where('shop_id', $event->shopId)
                    ->whereNull('withdrawn_at')
                    ->update(['withdrawn_at' => $event->deactivatedAt]);

                return $hashes;
            }, 5
        );

        foreach ($tokenHashes as $hash) {
            Cache::store('redis')->forget('mcp:principal:'.$hash);
        }
    }
}
