<?php

declare(strict_types=1);

namespace App\Http\Resources\Mcp;

use App\Models\McpToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property McpToken $resource */
final class McpTokenResource extends JsonResource
{
    public function __construct(McpToken $token)
    {
        parent::__construct($token);
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(Request $request): array
    {
        $token = $this->resource;

        return [
            'id' => $token->id,
            'name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'created_by_staff_ref' => $token->created_by_staff_ref,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'is_revoked' => $token->isRevoked(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'created_at' => $token->created_at->toIso8601String(),
        ];
    }
}
