<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\McpToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class TouchMcpTokenLastUsed implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tokenId,
        public CarbonImmutable $usedAt,
    ) {}

    public function handle(): void
    {
        McpToken::query()
            ->where('id', $this->tokenId)
            ->update(['last_used_at' => McpToken::microsecondTimestamp($this->usedAt)]);
    }
}
