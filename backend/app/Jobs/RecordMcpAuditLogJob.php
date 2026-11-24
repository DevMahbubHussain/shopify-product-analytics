<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\McpAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

final class RecordMcpAuditLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $shopId,
        public string $connectionType,
        public ?string $connectionId,
        public string $method,
        public ?string $targetName,
        public string $status,
        public int $durationMs,
        public ?string $clientName,
        public ?string $protocolVersion,
        public string $correlationId,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function handle(): void
    {
        McpAuditLog::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'connection_type' => $this->connectionType,
            'connection_id' => $this->connectionId,
            'method' => $this->method,
            'target_name' => $this->targetName,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'client_name' => $this->clientName,
            'protocol_version' => $this->protocolVersion,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt ?? CarbonImmutable::now(),
        ]);
    }
}
