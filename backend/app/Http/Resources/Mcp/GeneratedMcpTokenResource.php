<?php

declare(strict_types=1);

namespace App\Http\Resources\Mcp;

use App\Data\Mcp\GeneratedMcpToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property GeneratedMcpToken $resource */
final class GeneratedMcpTokenResource extends JsonResource
{
    public function __construct(GeneratedMcpToken $generated)
    {
        parent::__construct($generated);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        $generated = $this->resource;

        return [
            'id' => $generated->token->id,
            'name' => $generated->token->name,
            'token_prefix' => $generated->token->token_prefix,
            'plaintext_token' => $generated->plaintextToken,
            'created_by_staff_ref' => $generated->token->created_by_staff_ref,
            'expires_at' => $generated->token->expires_at?->toIso8601String(),
            'created_at' => $generated->token->created_at->toIso8601String(),
        ];
    }
}
