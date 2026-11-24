<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TenantPurgeRequested;
use App\Models\McpToken;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

final class PurgeMcpCacheOnTenantPurge
{
    public function handle(TenantPurgeRequested $event): void
    {
        /** @var Collection<int, McpToken> $tokens */
        $tokens = McpToken::query()
            ->where('shop_id', $event->shopId)
            ->get(['id', 'token_hash']);

        foreach ($tokens as $token) {
            Cache::store('redis')->forget('mcp:principal:'.$token->token_hash);
            Cache::store('redis')->forget('mcp:last_used:'.$token->id);
        }
    }
}
