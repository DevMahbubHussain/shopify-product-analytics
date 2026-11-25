<?php

declare(strict_types=1);

namespace App\Http\Resources\Mcp;

use App\Models\McpAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin McpAuditLog
 */
final class McpAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'connection_type' => $this->connection_type,
            'method' => $this->method,
            'target_name' => $this->target_name,
            'status' => $this->status,
            'duration_ms' => $this->duration_ms,
            'client_name' => $this->client_name,
            'protocol_version' => $this->protocol_version,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
